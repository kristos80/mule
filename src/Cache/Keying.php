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
		$verdicts = [];
		$gaps = [];

		foreach(Report::reports($this->filesystem->entriesUnder($coverageXml)) as $report) {
			$file = CoveredFile::of($this->filesystem->read($report), $root, $report);
			$path = $this->project->relative($file->path);

			if(!$file->isFullyCovered()) {
				$gaps[$path] = $file->unreached;

				continue;
			}

			$verdicts[$path] = new Verdict(
				$this->filesystem->digest($file->path),
				$this->coveringDigest($file),
			);
		}

		return new Reading(Verdicts::of($verdicts), $gaps);
	}

	/**
	 * One digest over every test file covering a source file, sorted so that
	 * the order the report happens to list them in cannot change it.
	 *
	 * **A new covering test cannot invalidate anything**, which is why this is
	 * taken over the files covering the source *today* rather than over the
	 * test suite as a whole: a mutant dies when any covering test fails, so
	 * adding a test can only kill more of them. Removing or weakening one is
	 * what matters, and that moves this digest. A whole-tree digest would have
	 * been sound too, and useless — nearly every change touches some test.
	 *
	 * **The paths in it are relative**, because the digest is written to a
	 * committed store and has to mean the same thing on the next machine.
	 *
	 * @param CoveredFile $file
	 * @return string
	 */
	private function coveringDigest(CoveredFile $file): string {
		$paths = [];

		foreach($file->coveringTests as $covering) {
			$paths[] = $this->testFileOf($covering);
		}

		$paths = array_unique($paths);

		sort($paths);

		return hash("sha256", implode("\n", array_map(
			fn(string $path): string => "{$path}:" . $this->filesystem->digest($this->project->absolute($path)),
			$paths,
		)));
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
