<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\Coverage\Timings;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\RecordingReports;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class ReportTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	private RecordingReports $reports;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem();
		$this->reports = new RecordingReports();
	}

	/**
	 * @param list<non-empty-string> $testIds
	 * @return Capture
	 */
	private function merged(array $testIds = ["Demo\\AaaTest::testOne", "Demo\\BbbTest::testTwo"]): Capture {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds($testIds);
		$coverage->setLineCoverage(["/src/A.php" => [1 => [0 => 1]]]);

		return Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/src",
			"codeCoverage" => $coverage,
			"testResults" => [],
		]);
	}

	/**
	 * @return Timings
	 */
	private function timings(): Timings {
		return Timings::of([
			"Demo\\AaaTest" => ["file" => "/p/tests/AaaTest.php", "time" => 0.1],
			"Demo\\BbbTest" => ["file" => "/p/tests/BbbTest.php", "time" => 0.2],
		]);
	}

	/**
	 * @return Report
	 */
	private function report(): Report {
		return new Report($this->filesystem, $this->reports);
	}

	public function testRendersTheAttributionIntoTheDirectoryItWasGiven(): void {
		$this->report()->write($this->merged(), $this->timings(), "/out");

		self::assertCount(1, $this->reports->rendered);
		self::assertSame("/out", $this->reports->rendered[0]["directory"]);
	}

	public function testHandsTheRendererTheMergedPayload(): void {
		$merged = $this->merged();

		$this->report()->write($merged, $this->timings(), "/out");

		self::assertSame($merged->payload(), $this->reports->rendered[0]["payload"]);
	}

	/**
	 * M1: the locator matches `junit.xml` in the coverage directory, beside
	 * `coverage-xml/`.
	 *
	 * @return void
	 */
	public function testWritesTheJunitReportWhereInfectionLooksForIt(): void {
		$this->report()->write($this->merged(), $this->timings(), "/out");

		self::assertSame(["/out/junit.xml"], array_keys($this->filesystem->files));
	}

	public function testTheJunitReportIsTheSynthesisedOneNotAMergedOne(): void {
		$this->report()->write($this->merged(), $this->timings(), "/out");

		self::assertSame($this->timings()->junit(), $this->filesystem->files["/out/junit.xml"]);
	}

	/**
	 * G4. Infection throws `TestNotFound` for a class absent from the JUnit
	 * report, so this stops earlier and says what to do about it.
	 *
	 * @return void
	 */
	public function testRefusesToWriteAReportWhoseTimingsAreIncomplete(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage(
			"The reference timings do not cover 1 test class(es) the coverage names: Demo\\CccTest."
			. " Take a fresh reference capture; a missing time must not be guessed at.",
		);

		$this->report()->write(
			$this->merged(["Demo\\AaaTest::testOne", "Demo\\CccTest::testThree"]),
			$this->timings(),
			"/out",
		);
	}

	public function testNamesEveryUntimedClassRatherThanTheFirst(): void {
		try {
			$this->report()->write(
				$this->merged(["Demo\\CccTest::testOne", "Demo\\DddTest::testTwo"]),
				$this->timings(),
				"/out",
			);
			self::fail("Expected a Failure.");
		} catch(Failure $failure) {
			self::assertStringContainsString("2 test class(es)", $failure->getMessage());
			self::assertStringContainsString("Demo\\CccTest, Demo\\DddTest", $failure->getMessage());
		}
	}

	/**
	 * Nothing is written when the check fails: a half-written report is one
	 * Infection would read.
	 *
	 * @return void
	 */
	public function testWritesNothingAtAllWhenTheTimingsAreIncomplete(): void {
		try {
			$this->report()->write($this->merged(["Demo\\CccTest::testOne"]), $this->timings(), "/out");
		} catch(Failure) {
			// the assertion is what did not happen
		}

		self::assertSame([], $this->filesystem->files);
		self::assertSame([], $this->reports->rendered);
	}
}
