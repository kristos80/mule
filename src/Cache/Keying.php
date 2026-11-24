<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

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
	 */
	public function __construct(
		private Project $project,
		private FilesystemInterface $filesystem,
		private ClassesInterface $classes,
	) {}

	/**
	 * What one coverage report says about every file in it: a verdict where
	 * the tests reach the whole file, and the gap where they do not.
	 *
	 * @param string $coverageXml The report directory.
	 * @return Reading
	 */
	public function of(string $coverageXml): Reading {
		$root = Report::sourceRoot($this->filesystem->read("{$coverageXml}/index.xml"));
		$verified = [];
		$gaps = [];
		$runs = [];

		foreach(Report::reports($this->filesystem->entriesUnder($coverageXml)) as $report) {
			$file = CoveredFile::of($this->filesystem->read($report), $root, $report);
			$path = $this->project->relative($file->path);

			foreach($this->testFilesOf($file) as $test) {
				$runs[$test][] = $path;
			}

			if($file->isFullyCovered()) {
				$verified[$path] = $this->filesystem->digest($file->path);

				continue;
			}

			$gaps[$path] = $file->unreached;
		}

		return new Reading(Snapshot::of($verified, $this->touched($runs)), $gaps);
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
