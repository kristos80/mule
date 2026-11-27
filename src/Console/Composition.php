<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Cache\Toolchain;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\Coverage\Soundness;
use Kristos80\Mule\Engine\Declaration;
use Kristos80\Mule\Doctor\Examination;
use Kristos80\Mule\Engine\Gate;
use Kristos80\Mule\External\ClassesInterface;
use Kristos80\Mule\External\CoverageReportInterface;
use Kristos80\Mule\External\EnvironmentInterface;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\External\LinesInterface;
use Kristos80\Mule\External\ProcessesInterface;
use Kristos80\Mule\External\ProgressInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Commands;
use Kristos80\Mule\Runner\Coverage;
use Kristos80\Mule\Runner\Engine;
use Kristos80\Mule\Runner\Run;
use Kristos80\Mule\Runner\Shards;
use Kristos80\Mule\Runner\Universe;
use function implode;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Where the parts are put together, and the only place that knows a path by
 * heart.
 *
 * **Every path here is project-relative, and that is a correctness property
 * rather than tidiness.** The rendered configuration is digested into the cache
 * key, so an absolute path inside it is a key no other machine can reproduce —
 * and a committed store nobody else can use is the same as no store at all.
 *
 * **The layout is one committed file beside one ignored directory.** The shard
 * cache for a 613-file project is 2.1 MB and the engine's scratch is larger
 * again; the store next to it is meant to be committed. `Shards::prepare()`
 * writes a `.gitignore` carrying `*` into the cache, so `git add .mule` stages
 * the store and nothing else.
 */
final readonly class Composition {

	/**
	 * The store, committed.
	 */
	public const string STORE = ".mule/verified.json";

	/**
	 * Shards, timings, captures, the rendered report and the engine's scratch —
	 * all machine-local, all under one ignored directory.
	 */
	public const string CACHE = ".mule/cache";

	/**
	 * The configurations PHPUnit itself looks for, in the order it prefers them.
	 *
	 * **Discovered rather than configured**, because mule starts PHPUnit without
	 * `--configuration` and so has to digest whichever file PHPUnit will find. A
	 * `mule.json` key naming a different one would be a key that lets the two
	 * disagree.
	 */
	private const array PHPUNIT = ["phpunit.xml", "phpunit.xml.dist"];

	/**
	 * @param FilesystemInterface $filesystem
	 * @param ProcessesInterface $processes
	 * @param ClassesInterface $classes
	 * @param CoverageReportInterface $reports
	 * @param LinesInterface $lines
	 * @param EnvironmentInterface $environment
	 * @param ProgressInterface $progress
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private ProcessesInterface $processes,
		private ClassesInterface $classes,
		private CoverageReportInterface $reports,
		private LinesInterface $lines,
		private EnvironmentInterface $environment,
		private ProgressInterface $progress,
	) {}

	/**
	 * @return Assembly
	 */
	public function assembled(): Assembly {
		$project = new Project($this->filesystem, $this->filesystem->resolve($this->environment->directory()));
		$configuration = Configuration::of($this->settings($project));
		$declaration = new Declaration($configuration, self::CACHE . "/outcome.json", self::CACHE . "/tmp");
		$commands = new Commands($configuration, $this->environment->interpreter(), $this->environment->ini());
		$shards = new Shards($this->filesystem, $project->absolute(self::CACHE));
		$universe = new Universe($this->filesystem, $project, $configuration);
		$coverage = new Coverage(
			$this->filesystem,
			$this->processes,
			$this->classes,
			$commands,
			$shards,
			$configuration,
			$project,
			$this->progress,
			self::CACHE,
		);
		$soundness = new Soundness($this->lines, $project, $configuration);

		return new Assembly(
			new Run(
				$configuration,
				$universe,
				$coverage,
				$soundness,
				new Engine(
					$this->filesystem,
					$this->processes,
					$commands,
					new Report($this->filesystem, $this->reports),
					$declaration,
					$project,
					$this->progress,
					self::CACHE,
				),
				new Store($this->filesystem, $project->absolute(self::STORE)),
				new Gate(),
				$this->toolchain($project, $configuration, $declaration, $universe->support()),
			),
			new Examination($universe, $coverage, $soundness),
			$universe,
		);
	}

	/**
	 * **An absent `mule.json` is a configured project, not an error.** Its
	 * defaults are `src` and `tests`, the whole default mutator set and a coverage
	 * floor of 100 — which is what a consumer following the HOWTO would have
	 * written out by hand.
	 *
	 * @param Project $project
	 * @return mixed
	 */
	private function settings(Project $project): mixed {
		$path = $project->absolute("mule.json");

		if(!$this->filesystem->exists($path)) {
			return [];
		}

		return json_decode($this->filesystem->read($path), associative: TRUE, flags: JSON_THROW_ON_ERROR);
	}

	/**
	 * @param Project $project
	 * @param Configuration $configuration
	 * @param Declaration $declaration
	 * @param string $support
	 * @return non-empty-string
	 */
	private function toolchain(
		Project $project,
		Configuration $configuration,
		Declaration $declaration,
		string $support,
	): string {
		return Toolchain::of(
			$this->digest($project, "composer.lock", "the pinned toolchain mule digests"),
			$this->digest($project, $this->phpunit($project), "what PHPUnit instruments"),
			// Handed over whole rather than pre-digested: `Toolchain::of()` hashes
			// the structure it is given, so hashing this one first was a digest of a
			// digest — and the gate proved the inner one made no difference.
			$declaration->rendered(),
			$configuration->mutators,
			$support,
			$this->environment->php(),
			$this->environment->driver(),
			$this->environment->mule(),
		);
	}

	/**
	 * **A missing input is refused rather than digested as absent.** A verdict
	 * proven without a lock file was proven against whatever happened to be
	 * installed, and a store full of those is a store that agrees with itself and
	 * nothing else (P3).
	 *
	 * @param Project $project
	 * @param string $relative
	 * @param string $why
	 * @return non-empty-string
	 */
	private function digest(Project $project, string $relative, string $why): string {
		$path = $project->absolute($relative);

		if(!$this->filesystem->exists($path)) {
			throw new Failure(
				"mule needs {$relative}, because it decides {$why} and therefore what a verdict"
				. " means. Nothing can be proven without it.",
			);
		}

		return $this->filesystem->digest($path);
	}

	/**
	 * @param Project $project
	 * @return string
	 */
	private function phpunit(Project $project): string {
		foreach(self::PHPUNIT as $candidate) {
			if($this->filesystem->exists($project->absolute($candidate))) {
				return $candidate;
			}
		}

		throw new Failure(
			"mule found none of " . implode(", ", self::PHPUNIT) . " in the project. mule starts"
			. " PHPUnit the way PHPUnit would start itself, so the configuration it will find is"
			. " the one mule has to digest — and there is not one.",
		);
	}
}
