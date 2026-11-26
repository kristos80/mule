<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Split;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class SplitTest extends TestCase {

	/**
	 * A capture of two test classes over two files: `A.php` line 1 run by both
	 * of `AaaTest`'s cases, line 2 by `BbbTest`; `B.php` line 7 executable and
	 * reached by nobody.
	 *
	 * @return Capture
	 */
	private function capture(): Capture {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([
			0 => "AaaTest::testOne",
			1 => "BbbTest::testTwo",
			2 => "AaaTest::testThree#a dataset",
		]);
		$coverage->setLineCoverage([
			"/src/A.php" => [1 => [0 => 1, 2 => 1], 2 => [1 => 1]],
			"/src/B.php" => [7 => []],
		]);

		return Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/src",
			"codeCoverage" => $coverage,
			"testResults" => [
				"AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.1],
				"BbbTest::testTwo" => ["size" => "unknown", "status" => "success", "time" => 0.2],
				"AaaTest::testThree#a dataset" => ["size" => "unknown", "status" => "success", "time" => 0.3],
			],
		]);
	}

	public function testOneShardPerTestClass(): void {
		self::assertSame(["AaaTest", "BbbTest"], array_keys(new Split($this->capture())->byTestClass()));
	}

	public function testADatasetCaseBelongsToItsClass(): void {
		$shard = new Split($this->capture())->byTestClass()["AaaTest"];

		self::assertSame(["AaaTest::testOne", "AaaTest::testThree#a dataset"], $shard->testIds());
	}

	/**
	 * A line run by several of the shard's own tests keeps all of them. Losing
	 * any would under-report which tests cover a line, which is the one thing
	 * the engine reads a report for.
	 *
	 * @return void
	 */
	public function testALineKeepsEveryOneOfTheShardsTestsThatRanIt(): void {
		$shard = new Split($this->capture())->byTestClass()["AaaTest"];

		self::assertSame([0 => 1, 1 => 1], $shard->coverage->lineCoverage()["/src/A.php"][1]);
	}

	public function testIndicesAreRenumberedAgainstTheShardsOwnTestList(): void {
		$shard = new Split($this->capture())->byTestClass()["BbbTest"];

		// index 1 in the whole capture is index 0 in a shard holding one test
		self::assertSame([2 => [0 => 1]], array_filter($shard->coverage->lineCoverage()["/src/A.php"]));
	}

	public function testALineRunByNoneOfTheShardsTestsIsKeptButEmpty(): void {
		$shard = new Split($this->capture())->byTestClass()["BbbTest"];

		self::assertSame([1 => [], 2 => [0 => 1]], $shard->coverage->lineCoverage()["/src/A.php"]);
	}

	public function testTheExecutableLineSkeletonSurvivesForEveryFile(): void {
		$shard = new Split($this->capture())->byTestClass()["AaaTest"];

		// B.php is touched by neither class, and still has to be in the shard:
		// it is the denominator a merged report's coverage percentage divides by
		self::assertSame(["/src/A.php", "/src/B.php"], array_keys($shard->coverage->lineCoverage()));
		self::assertSame([7 => []], $shard->coverage->lineCoverage()["/src/B.php"]);
	}

	public function testNullLinesStayNull(): void {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds(["AaaTest::testOne"]);
		$coverage->setLineCoverage(["/src/A.php" => [1 => [0 => 1], 2 => NULL]]);

		$capture = Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/src",
			"codeCoverage" => $coverage,
			"testResults" => ["AaaTest::testOne" => ["size" => "u", "status" => "success", "time" => 0.1]],
		]);

		self::assertNull(new Split($capture)->byTestClass()["AaaTest"]->coverage->lineCoverage()["/src/A.php"][2]);
	}

	public function testAShardCarriesOnlyItsOwnTestResults(): void {
		$shard = new Split($this->capture())->byTestClass()["BbbTest"];

		// the values matter as much as the keys: these are the timings a
		// reference capture is later compared against, so a shard holding
		// indices where it should hold results would read as valid
		self::assertSame([
			"BbbTest::testTwo" => ["size" => "unknown", "status" => "success", "time" => 0.2],
		], $shard->testResults);
	}

	/**
	 * M16: a test class that executes no instrumented source is absent from
	 * `testIds()`, so no shard is cut for it. Measured on a real project as
	 * three pure enum tests. mule must record that rather than infer it, so
	 * this is pinned as behaviour.
	 *
	 * @return void
	 */
	public function testATestClassThatExecutesNoSourceGetsNoShard(): void {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds(["AaaTest::testOne"]);
		$coverage->setLineCoverage(["/src/A.php" => [1 => [0 => 1]]]);

		$capture = Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/src",
			"codeCoverage" => $coverage,
			"testResults" => [
				"AaaTest::testOne" => ["size" => "u", "status" => "success", "time" => 0.1],
				"EnumTest::testCases" => ["size" => "u", "status" => "success", "time" => 0.1],
			],
		]);

		self::assertSame(["AaaTest"], array_keys(new Split($capture)->byTestClass()));
	}

	public function testTheBasePathIsCarriedThroughUnchanged(): void {
		self::assertSame("/src", new Split($this->capture())->byTestClass()["AaaTest"]->basePath);
	}
}
