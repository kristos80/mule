<?php
declare(strict_types=1);

namespace Kristos80\Mule\Config;

use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\External\ProcessesInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Json\Decoded;
use Kristos80\Mule\Project;

/**
 * What mule needs to know about a project, inferred where it can be and
 * declared in mule's own words where it cannot.
 *
 * **One file, and it names no other tool.** A project says what it wants
 * gated; which test runner measures coverage and which engine mutates is
 * mule's business and appears nowhere here. The cost is deliberate: every
 * setting worth having has to be given a name in this vocabulary, and nothing
 * is passed through to the engine raw. The benefit is that mule's own choices
 * cannot be contradicted by a project that never knew they existed — there is
 * no `--min-msi` to smuggle past, because there is nowhere to write one.
 *
 * **Inference over declaration.** The source roots come from the project's own
 * autoloader, the runner from whichever one is installed, and the engine ships
 * with mule. A project with nothing to exclude and no opinion about mutators
 * needs no `mule.json` at all.
 *
 * **Every key here changes what a verdict means, so the whole file is
 * digested** into the store beside the verdicts — see {@see \Kristos80\Mule\Cache\Declared}.
 * That is only sound because nothing machine-local is left in it: enabling a
 * coverage driver is done to the environment mule runs in, not written down
 * here.
 */
final readonly class Configuration {

	private const string FILE = "mule.json";

	/**
	 * Every key mule.json may carry.
	 *
	 * **An unknown key is refused rather than ignored.** Mule owns this whole
	 * vocabulary, so a key it does not recognise is a mistake every time — a
	 * misspelling that would otherwise take effect as silence, or an attempt to
	 * reach the engine directly.
	 *
	 * @var list<string>
	 */
	private const array KEYS = [
		"source",
		"exclude",
		"tests",
		"narrow",
		"mutators",
		"mutate-uncovered",
		"min-coverage",
		"timeout",
		"threads",
	];

	/**
	 * The mutator families mule exposes, in mule's spelling.
	 *
	 * Written out rather than read from the engine, whose own list is marked
	 * internal. A family the engine gains and mule has not been told about is a
	 * missing feature with a clear error, which is a better failure than a name
	 * forwarded blindly and refused downstream in a file the project never
	 * wrote.
	 *
	 * @var list<string>
	 */
	private const array FAMILIES = [
		"arithmetic",
		"boolean",
		"cast",
		"conditional-boundary",
		"conditional-negotiation",
		"default",
		"equal",
		"extensions",
		"function-signature",
		"identical",
		"loop",
		"nullify",
		"number",
		"operator",
		"regex",
		"removal",
		"return-value",
		"sort",
		"unwrap",
	];

	/**
	 * What mule gates on when a project says nothing.
	 *
	 * `identical` is on because a project with no loose comparison today pays
	 * nothing for it and is caught the day it gains one. `equal` is off: under
	 * strict types its mutants are almost all equivalent, and an unkillable
	 * mutant makes a full-score gate unpassable.
	 *
	 * @var list<string>
	 */
	private const array FAMILIES_BY_DEFAULT = ["default", "identical"];

	/**
	 * @param list<string> $runner The command that measures coverage, less what it is measuring.
	 * @param list<string> $source Project-relative directories to mutate.
	 * @param list<string> $exclude Project-relative paths to leave out of both halves.
	 * @param string|null $tests Which suite coverage is worked out from, or NULL for all of them.
	 * @param bool $narrow Whether a coverage run may execute only the affected tests.
	 * @param list<string> $mutators The engine's own names for the families asked for.
	 * @param bool $mutateUncovered Whether code no test reaches should be mutated too.
	 * @param int|null $minCoverage The percentage of lines that must be covered, or NULL to assert nothing.
	 * @param int|null $timeout Seconds a single mutant may run for, or NULL for the engine's own.
	 * @param string|null $threads How many mutants to run at once, or NULL for the engine's own.
	 */
	public function __construct(
		public array $runner,
		public array $source,
		public array $exclude,
		public ?string $tests,
		public bool $narrow,
		public array $mutators,
		public bool $mutateUncovered,
		public ?int $minCoverage,
		public ?int $timeout,
		public ?string $threads,
	) {}

	/**
	 * A project's configuration: `mule.json` where there is one, inference
	 * everywhere else.
	 *
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @param ProcessesInterface $processes
	 * @return self
	 */
	public static function load(Project $project, FilesystemInterface $filesystem, ProcessesInterface $processes): self {
		$file = $project->absolute(self::FILE);
		$declared = Decoded::of($filesystem->exists($file) ? $filesystem->read($file) : "{}", self::FILE);

		self::refuseUnknownKeys($declared);

		return new self(
			self::runnerOf($project, $filesystem, $processes),
			self::sourceOf($declared, $project, $filesystem),
			$declared->strings("exclude"),
			$declared->stringOr("tests", NULL),
			$declared->flag("narrow", FALSE),
			self::mutatorsOf($declared),
			$declared->flag("mutate-uncovered", FALSE),
			$declared->numberOr("min-coverage", NULL),
			$declared->numberOr("timeout", NULL),
			$declared->stringOr("threads", NULL),
		);
	}

	/**
	 * The paths to leave out, relative to the source root each one sits under,
	 * which is the form the engine wants them in.
	 *
	 * **The engine applies these to every source directory it is given**, not
	 * to the one a path was written under: its collector hands the list to a
	 * finder scanning all of them at once. So `lib/Generated` and
	 * `src/Generated` cannot be told apart, and excluding either excludes both.
	 * That is the engine's meaning and mule adopts it rather than fighting it —
	 * see {@see self::excludes()}, which asks the same question of a file so the
	 * two sides cannot disagree. A project wanting one and not the other has to
	 * name them differently.
	 *
	 * A path under no source root is refused: it would silently exclude nothing
	 * from mutation while still being hidden from the coverage report, which is
	 * the one combination that can make a file look proven when nothing mutated
	 * it.
	 *
	 * @return list<string>
	 */
	public function excludedWithinSource(): array {
		$within = [];

		foreach($this->exclude as $path) {
			$within[] = $this->strippedOf($path);
		}

		return $within;
	}

	/**
	 * Whether a project-relative source path is one the gate leaves out.
	 *
	 * **Asked in the engine's terms, deliberately.** Both sides compare the
	 * path as it reads inside its source root, so a file the engine will not
	 * mutate is a file mule will not read a verdict for — however many source
	 * roots there are and whichever of them the exclusion was written under.
	 * Answered the other way round, by project-relative path, a second root
	 * holding the same relative path would be mutated by nothing, stay in the
	 * coverage report, and be banked as proven.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function excludes(string $path): bool {
		$within = $this->withinSource($path);

		if($within === NULL) {
			return FALSE;
		}

		foreach($this->excludedWithinSource() as $excluded) {
			if($within === $excluded || str_starts_with($within, "{$excluded}/")) {
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private function strippedOf(string $path): string {
		$within = $this->withinSource($path);
		$file = self::FILE;

		return $within
			?? throw new Failure("The excluded path {$path} in {$file} is not inside any of the source directories, so mule cannot leave it out of the gate.");
	}

	/**
	 * A path as it reads inside whichever source root holds it, or NULL where
	 * none of them does.
	 *
	 * @param string $path
	 * @return string|null
	 */
	private function withinSource(string $path): ?string {
		foreach($this->source as $root) {
			if(str_starts_with($path, "{$root}/")) {
				return substr($path, strlen($root) + 1);
			}
		}

		return NULL;
	}

	/**
	 * The directories to mutate: declared, or the ones the project already told
	 * Composer its own code lives in.
	 *
	 * **`autoload`, never `autoload-dev`.** The autoloader mule asks about
	 * classes answers for both at once, which is right for working out what
	 * files exist and wrong here: a project does not want its tests mutated.
	 * The declaration in `composer.json` is the only place the two are still
	 * told apart.
	 *
	 * @param Decoded $declared
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @return list<string>
	 */
	private static function sourceOf(Decoded $declared, Project $project, FilesystemInterface $filesystem): array {
		$source = $declared->strings("source");

		if($source !== []) {
			return $source;
		}

		$composer = Decoded::of($filesystem->read($project->absolute("composer.json")), "composer.json");
		$inferred = $composer->object("autoload")->paths("psr-4");

		if($inferred === []) {
			$file = self::FILE;

			throw new Failure("This project autoloads nothing under psr-4, so mule cannot tell what to mutate: name the directories as source in {$file}.");
		}

		return $inferred;
	}

	/**
	 * @param Decoded $declared
	 * @return void
	 */
	private static function refuseUnknownKeys(Decoded $declared): void {
		$file = self::FILE;

		foreach($declared->names() as $name) {
			if(!in_array($name, self::KEYS, TRUE)) {
				throw new Failure("The key {$name} in {$file} is not one mule has: " . implode(", ", self::KEYS) . ".");
			}
		}
	}

	/**
	 * The mutator families asked for, translated into the engine's spelling.
	 *
	 * @param Decoded $declared
	 * @return list<string>
	 */
	private static function mutatorsOf(Decoded $declared): array {
		$families = in_array("mutators", $declared->names(), TRUE)
			? $declared->enabled("mutators")
			: self::FAMILIES_BY_DEFAULT;
		$file = self::FILE;
		$mutators = [];

		foreach($families as $family) {
			if(!in_array($family, self::FAMILIES, TRUE)) {
				throw new Failure("The mutator family {$family} in {$file} is not one mule has: " . implode(", ", self::FAMILIES) . ".");
			}

			$mutators[] = "@" . str_replace("-", "_", $family);
		}

		return $mutators;
	}

	/**
	 * Whichever test runner the project installed, run under the very PHP mule
	 * is running under.
	 *
	 * **Named rather than left to a shebang, and handed mule's own ini.** A
	 * coverage driver is enabled for an interpreter, and usually by an ini: a
	 * project that turns one on by wrapping mule gets it for mule's process,
	 * and the runner's `#!/usr/bin/env php` would resolve something else off
	 * the path. Naming the binary is not enough either — measured under such a
	 * wrapper, a child started as plain `PHP_BINARY` loads no ini and has no
	 * driver, so the ini goes with it.
	 *
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @param ProcessesInterface $processes
	 * @return list<string>
	 */
	private static function runnerOf(Project $project, FilesystemInterface $filesystem, ProcessesInterface $processes): array {
		$runner = self::installed("pest", $project, $filesystem) ? "pest" : "phpunit";
		$ini = $processes->iniFile();
		$interpreter = $ini === FALSE ? [PHP_BINARY] : [PHP_BINARY, "-c", $ini];

		return [...$interpreter, "./vendor/bin/{$runner}"];
	}

	/**
	 * @param string $binary
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @return bool
	 */
	private static function installed(string $binary, Project $project, FilesystemInterface $filesystem): bool {
		return $filesystem->exists($project->absolute("vendor/bin/{$binary}"));
	}
}
