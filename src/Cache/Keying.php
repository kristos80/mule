<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\CoveredFile;
use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\External\ClassesInterface;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Project;

/**
 * Turns a coverage report into the verdict every covered file has right now.
 */
final readonly class Keying {

	/**
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @param ClassesInterface $classes
	 * @param Universe $universe
	 * @param Declared $declared
	 * @param Configuration $configuration
	 */
	public function __construct(
		private Project $project,
		private FilesystemInterface $filesystem,
		private ClassesInterface $classes,
		private Universe $universe,
		private Declared $declared,
		private Configuration $configuration,
	) {}

	/**
	 * What one coverage report says about every file in it: a verdict where
	 * the tests reach the whole file, and the gap where they do not.
	 *
	 * **A report may be partial.** A run that asked the test runner for a
	 * subset still gets a report naming every source file, most of them with
	 * no covering test at all — so when `$only` is given, every other path in
	 * it is ignored rather than read as "nothing covers this now". Nothing is
	 * watched either: what the whole tree looks like is not something a
	 * partial report can say.
	 *
	 * **How much of a file was reached is recorded whether the report is whole
	 * or not.** It is a fact about one file, measured from that file's own
	 * report, so a subset run holds the truth about every path it was given —
	 * and the store already holds it about the rest.
	 *
	 * @param string $coverageXml The report directory.
	 * @param list<string>|null $only The paths this report is authoritative about.
	 * @return Reading
	 */
	public function of(string $coverageXml, ?array $only = NULL): Reading {
		$root = Report::sourceRoot($this->filesystem->read("{$coverageXml}/index.xml"));
		$verified = [];
		$gaps = [];
		$runs = [];
		$coverage = [];

		foreach($this->filesIn($coverageXml, $root, $only) as $path => $file) {
			foreach($this->testFilesOf($file) as $test) {
				$runs[$test][] = $path;
			}

			$coverage[$path] = $file->reach;

			if($file->reach->isComplete()) {
				$verified[$path] = $this->filesystem->digest($file->path);

				continue;
			}

			$gaps[$path] = $file->unreached;
		}

		$tests = $this->touched($runs);
		$unproven = $this->unproven($gaps);
		$known = [...$verified, ...$tests, ...$unproven];
		$whole = $only === NULL;

		return new Reading(
			Snapshot::of(
				$verified,
				$tests,
				$unproven,
				$whole ? $this->watched($known) : [],
				$whole ? $this->declared->digests() : [],
				$coverage,
				$whole ? $this->declared->engine() : [],
			),
			$gaps,
		);
	}

	/**
	 * Every per-file report this run is allowed to draw conclusions from,
	 * keyed by the source file it is about.
	 *
	 * @param string $coverageXml
	 * @param string $root
	 * @param list<string>|null $only
	 * @return array<string, CoveredFile>
	 */
	private function filesIn(string $coverageXml, string $root, ?array $only): array {
		$authoritative = $only === NULL ? NULL : array_flip($only);
		$files = [];

		foreach(Report::reports($this->filesystem->entriesUnder($coverageXml)) as $report) {
			$file = CoveredFile::of($this->filesystem->read($report), $root, $report);
			$path = $this->project->relative($file->path);

			if(($authoritative === NULL || isset($authoritative[$path])) && !$this->configuration->excludes($path)) {
				$files[$path] = $file;
			}
		}

		return $files;
	}

	/**
	 * The files the tests do not reach all of, with the digest they were
	 * measured against.
	 *
	 * @param array<string, list<\Kristos80\Mule\Coverage\Unreached>> $gaps
	 * @return array<string, Unproven>
	 */
	private function unproven(array $gaps): array {
		$unproven = [];

		foreach($gaps as $path => $unreached) {
			$unproven[$path] = new Unproven($this->filesystem->digest($this->project->absolute($path)), $unreached);
		}

		return $unproven;
	}

	/**
	 * Everything else whose bytes could change what a later run would find:
	 * every file the project autoloads that the report never mentioned — an
	 * interface, a file excluded from coverage, a test nothing has run yet.
	 *
	 * **Without this a later run could not tell "nothing has changed" from
	 * "nothing I happen to know about has changed".** A file that appears is
	 * exactly the case a digest of known files cannot see.
	 *
	 * @param array<string, mixed> $known Everything already recorded elsewhere.
	 * @return array<string, string>
	 */
	private function watched(array $known): array {
		$watched = [];

		foreach($this->universe->paths() as $path) {
			if(!isset($known[$path])) {
				$watched[$path] = $this->filesystem->digest($this->project->absolute($path));
			}
		}

		return $watched;
	}

	/**
	 * Each test file, with its digest and everything it was seen to run.
	 *
	 * @param array<string, list<string>> $runs Test path to the source paths credited to it.
	 * @return array<string, Touched>
	 */
	private function touched(array $runs): array {
		$tests = [];

		foreach($runs as $test => $paths) {
			$touches = [];

			foreach($paths as $path) {
				$touches[$path] = $path;
			}

			ksort($touches);

			$tests[$test] = new Touched($this->filesystem->digest($this->project->absolute($test)), array_keys($touches));
		}

		return $tests;
	}

	/**
	 * The file of every test the report credits with this one, repeats and
	 * all: one per covering method, deduplicated where they are recorded.
	 *
	 * @param CoveredFile $file
	 * @return list<string>
	 */
	private function testFilesOf(CoveredFile $file): array {
		$paths = [];

		foreach($file->coveringTests as $covering) {
			$paths[] = $this->testFileOf($covering);
		}

		return $paths;
	}

	/**
	 * The project-relative file a covering test lives in, named in the report
	 * as `Class::method`.
	 *
	 * A class the project's own autoloader cannot place is a test whose edits
	 * would silently stop invalidating anything, so this fails loudly rather
	 * than skipping it.
	 *
	 * @param string $covering
	 * @return string
	 */
	private function testFileOf(string $covering): string {
		$class = strstr($covering, "::", TRUE) ?: $covering;
		$file = $this->classes->fileOf($class);

		if($file === FALSE) {
			throw new Failure("The project's autoloader cannot place the covering test {$class}, so mule cannot find its file.");
		}

		return $this->project->relative($file);
	}
}
