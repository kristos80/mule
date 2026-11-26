<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\External\CoverageReportInterface;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Failure;
use function implode;

/**
 * Writes the report directory Infection reads.
 *
 * **Two artifacts, two sources, which is the whole point.** `--coverage` with
 * `--skip-initial-tests` wants `coverage-xml/index.xml` with a document per
 * source file, and `junit.xml` beside it (M1). The first comes from the merged
 * shards; the second is *synthesised* from a single reference capture's timings,
 * because summing times across shards inflates them and Infection then silently
 * discards the mutants that look slow (M12, M13).
 *
 * Nothing here merges a JUnit report. There is deliberately no way to.
 */
final readonly class Report {

	/**
	 * What Infection's locators match: `coverage-xml/index.xml` and a file named
	 * `junit.xml`, both inside the directory handed to `--coverage` (M1).
	 */
	private const string COVERAGE_XML = "coverage-xml";

	private const string JUNIT = "junit.xml";

	/**
	 * @param FilesystemInterface $filesystem
	 * @param CoverageReportInterface $reports
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private CoverageReportInterface $reports,
	) {}

	/**
	 * @param Capture $merged The reassembled attribution (`Merge`).
	 * @param Timings $timings The reference capture's per-class times.
	 * @param string $directory Where to write it; passed to Infection as `--coverage`.
	 * @return void
	 */
	public function write(Capture $merged, Timings $timings, string $directory): void {
		$this->assertTimed($merged, $timings);

		if(!$this->filesystem->isDirectory($directory)) {
			$this->filesystem->createDirectory($directory);
		}

		$this->reports->renderXml(
			$merged->coverage,
			$merged->testResults,
			$merged->basePath,
			"{$directory}/" . self::COVERAGE_XML,
		);
		$this->filesystem->write("{$directory}/" . self::JUNIT, $timings->junit());
	}

	/**
	 * **G4, and it has a real exception behind it.**
	 * `JUnitTestFileDataProvider::getTestFileInfo()` throws `TestNotFound` for a
	 * class it cannot find in the JUnit report, so a class the attribution names
	 * and the timings do not would stop the run — later, further away, and with
	 * Infection's message rather than one that says what to do about it.
	 *
	 * The usual cause is a test added since the last reference capture, and the
	 * answer is to take another one. What must *not* happen is defaulting the
	 * missing time to zero: zero is the one value that can never look slow, so it
	 * would hide the gap instead of reporting it.
	 *
	 * @param Capture $merged
	 * @param Timings $timings
	 * @return void
	 */
	private function assertTimed(Capture $merged, Timings $timings): void {
		$missing = $timings->missing($merged->testClasses());

		if($missing !== []) {
			throw new Failure(
				"The reference timings do not cover " . count($missing) . " test class(es) the coverage"
				. " names: " . implode(", ", $missing) . ". Take a fresh reference capture;"
				. " a missing time must not be guessed at.",
			);
		}
	}
}
