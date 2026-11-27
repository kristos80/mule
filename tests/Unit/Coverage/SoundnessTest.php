<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Coverage\Soundness;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\StubLines;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class SoundnessTest extends TestCase {

	/**
	 * A report over one file whose lines 3, 4 and 5 are executable and whose 3 and
	 * 4 were run.
	 *
	 * @param array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>> $lines
	 * @return Capture
	 */
	private function capture(array $lines = ["A.php" => [3 => [0 => 1], 4 => [0 => 1], 5 => []]]): Capture {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([0 => "AaaTest::testOne"]);
		$coverage->setLineCoverage($lines);

		return Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/p/src",
			"codeCoverage" => $coverage,
			"testResults" => ["AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.1]],
		]);
	}

	/**
	 * @param array<string, list<int>> $executable
	 * @param int $floor
	 * @return Soundness
	 */
	private function soundness(
		array $executable = ["/p/src/A.php" => [3, 4, 5]],
		int $floor = 100,
	): Soundness {
		return new Soundness(
			new StubLines($executable),
			new Project(new InMemoryFilesystem(), "/p"),
			Configuration::of(["min-coverage" => $floor]),
		);
	}

	/**
	 * @param array<string, list<int>> $executable
	 * @param int $floor
	 * @param Capture|null $capture
	 * @param list<string> $gated
	 * @return array<string, Reach>
	 */
	private function reach(
		array $executable = ["/p/src/A.php" => [3, 4, 5]],
		int $floor = 100,
		?Capture $capture = NULL,
		array $gated = ["src/A.php"],
	): array {
		return $this->soundness($executable, $floor)->reach($capture ?? $this->capture(), $gated);
	}

	public function testItCountsWhatTheSuiteRunsAndWhatItCould(): void {
		$reach = $this->reach();

		self::assertSame(3, $reach["src/A.php"]->executable);
		self::assertSame(2, $reach["src/A.php"]->executed);
	}

	public function testAFullyCoveredFileMeetsTheFloor(): void {
		$reach = $this->reach(
			["/p/src/A.php" => [3, 4]],
			capture: $this->capture(["A.php" => [3 => [0 => 1], 4 => [0 => 1]]]),
		);

		self::assertTrue($reach["src/A.php"]->meets);
	}

	/**
	 * **The gap Infection cannot report.** Line 5 is executable and never run, but
	 * if nothing on it is mutable the engine generates no mutant for it — so every
	 * mutant of the file is killed and it banks as proven with a chunk never
	 * executed. Counting lines is the only thing that sees it.
	 *
	 * @return void
	 */
	public function testALineNobodyRanIsAFinding(): void {
		self::assertFalse($this->reach()["src/A.php"]->meets);
	}

	public function testEveryFileAskedAboutIsAnswered(): void {
		$reach = $this->reach(
			["/p/src/A.php" => [3, 4, 5], "/p/src/B.php" => [1]],
			gated: ["src/A.php", "src/B.php"],
		);

		self::assertFalse($reach["src/A.php"]->meets);
		self::assertFalse($reach["src/B.php"]->meets);
	}

	public function testAFileTheReportHasNeverHeardOfIsAFinding(): void {
		$reach = $this->reach(["/p/src/B.php" => [1, 2]], gated: ["src/B.php"]);

		self::assertFalse($reach["src/B.php"]->meets);
		self::assertSame(0, $reach["src/B.php"]->executed);
	}

	/**
	 * An interface or an enum of constants has no lines to reach, and 0 of 0 is not
	 * a gap — it is G1's business, which says so in a message naming `exclude`.
	 *
	 * @return void
	 */
	public function testAFileWithNothingExecutableInItPasses(): void {
		self::assertTrue($this->reach(["/p/src/Thing.php" => []], gated: ["src/Thing.php"])["src/Thing.php"]->meets);
	}

	public function testTheFloorIsThePersonsChoice(): void {
		self::assertTrue($this->reach(floor: 66)["src/A.php"]->meets);
	}

	/**
	 * Two lines of three is 66 %, so a floor of 67 catches it and a floor of 66
	 * does not. Rounding up would let a file one line short of the bar pass it.
	 *
	 * @return void
	 */
	public function testTheFloorIsNotRoundedInTheProjectsFavour(): void {
		self::assertFalse($this->reach(floor: 67)["src/A.php"]->meets);
	}

	public function testAFloorOfNothingAsksForNothing(): void {
		self::assertTrue($this->reach(["/p/src/A.php" => [1, 2, 3, 4, 5]], 0)["src/A.php"]->meets);
	}

	public function testOnlyTheFilesAskedAboutAreAnswered(): void {
		self::assertSame([], $this->reach(gated: []));
	}

	/**
	 * **G3.** A report naming a line the file does not have is a shard describing
	 * a version of it that is gone, which is the one failure a coverage cache can
	 * produce on its own. G1 cannot see it: the file still parses, still produces
	 * its mutants and still gets a score — the score is just about the wrong lines.
	 *
	 * @return void
	 */
	public function testALineTheFileDoesNotHaveFailsTheRun(): void {
		$this->expectExceptionMessage(
			"The coverage for \"src/A.php\" names line(s) 5, which that file does not have",
		);

		$this->reach(
			["/p/src/A.php" => [3, 4]],
			capture: $this->capture(["A.php" => [3 => [0 => 1], 4 => [0 => 1], 5 => [0 => 1]]]),
		);
	}

	public function testItSaysWhatToDoAboutAStaleShard(): void {
		$this->expectExceptionMessage("Run mule --whole. Nothing was written.");

		$this->reach([]);
	}

	/**
	 * An unreached line still counts: a shard whose line numbers have shifted
	 * reports the skeleton of a file that is gone, whether or not anything ran it.
	 *
	 * @return void
	 */
	public function testAnInvalidLineCountsEvenWhenNobodyRanIt(): void {
		$this->expectExceptionMessage("names line(s) 5");

		$this->reach(["/p/src/A.php" => [3, 4]]);
	}

	/**
	 * The report spells a file relative to the source root; everything else mule
	 * does spells it relative to the project. Getting that wrong would make every
	 * file look entirely unreached.
	 *
	 * @return void
	 */
	public function testAPathOutsideTheSourceRootIsLookedUpAsItIs(): void {
		self::assertTrue(
			$this->reach(
				["/p/tests/Helper.php" => [1]],
				capture: $this->capture(["tests/Helper.php" => [1 => [0 => 1]]]),
				gated: ["tests/Helper.php"],
			)["tests/Helper.php"]->meets,
		);
	}
}
