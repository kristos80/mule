<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Shard;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class ShardTest extends TestCase {

	/**
	 * A split shard as `Split` leaves it: faithful to the capture, so lines its
	 * own test never ran are present as `[]`.
	 *
	 * @return Capture
	 */
	private function split(): Capture {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds(["AaaTest::testOne", "AaaTest::testTwo"]);
		$coverage->setLineCoverage([
			// two covered lines, so truncating a file's lines is visible
			"/src/A.php" => [1 => [0 => 1, 1 => 1], 2 => [1 => 1], 3 => [], 4 => NULL],
			// a second covered file, so truncating the file list is visible
			"/src/B.php" => [7 => [0 => 1]],
			// and one with nothing on it, which has no business being stored
			"/src/C.php" => [9 => []],
		]);

		return Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/src",
			"codeCoverage" => $coverage,
			"testResults" => [
				"AaaTest::testOne" => ["size" => "u", "status" => "success", "time" => 0.1],
				"AaaTest::testTwo" => ["size" => "u", "status" => "success", "time" => 0.2],
			],
		]);
	}

	/**
	 * @return Shard
	 */
	private function shard(): Shard {
		return Shard::of("AaaTest", $this->split(), [
			"tests/AaaTest.php" => "t-digest",
			"src/A.php" => "a-digest",
			"src/B.php" => "b-digest",
		]);
	}

	public function testKeepsOnlyTheEntriesThatNameATest(): void {
		self::assertSame([
			"/src/A.php" => [1 => [0, 1], 2 => [1]],
			"/src/B.php" => [7 => [0]],
		], $this->shard()->lines);
	}

	public function testDropsAFileWhoseEveryLineNamesNoTest(): void {
		self::assertArrayNotHasKey("/src/C.php", $this->shard()->lines);
	}

	public function testKeepsTheTestIdsItsLineDataIndexesInto(): void {
		self::assertSame(["AaaTest::testOne", "AaaTest::testTwo"], $this->shard()->testIds);
	}

	public function testRehydratesIntoACaptureMergeCanTake(): void {
		$capture = $this->shard()->capture($this->split(), "/elsewhere");

		self::assertSame([
			"/src/A.php" => [1 => [0 => 1, 1 => 1], 2 => [1 => 1]],
			"/src/B.php" => [7 => [0 => 1]],
		], $capture->coverage->lineCoverage());
		self::assertSame(["AaaTest::testOne", "AaaTest::testTwo"], $capture->testIds());
		self::assertSame("/elsewhere", $capture->basePath);
	}

	public function testSurvivesTheRoundTripThroughJson(): void {
		$shard = $this->shard();

		$json = json_encode($shard->toArray(), JSON_THROW_ON_ERROR);

		$decoded = Shard::fromArray(json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR));

		self::assertSame($shard->toArray(), $decoded->toArray());
	}

	/**
	 * The reuse rule, which is the whole reason the digests are stored.
	 *
	 * @return void
	 */
	public function testIsReusableWhileEverythingItRecordedStillHashesTheSame(): void {
		self::assertTrue($this->shard()->matches([
			"tests/AaaTest.php" => "t-digest",
			"src/A.php" => "a-digest",
			"src/B.php" => "b-digest",
		]));
	}

	public function testIsNotReusableOnceItsOwnTestMoved(): void {
		self::assertFalse($this->shard()->matches([
			"tests/AaaTest.php" => "moved",
			"src/A.php" => "a-digest",
			"src/B.php" => "b-digest",
		]));
	}

	/**
	 * The case that makes this stricter than "its test did not change": a shard
	 * holds line data for files other than its own, and reusing it after one of
	 * those moved puts coverage for the old line numbers into the merged report.
	 *
	 * @return void
	 */
	public function testIsNotReusableOnceASourceFileItCoveredMoved(): void {
		self::assertFalse($this->shard()->matches([
			"tests/AaaTest.php" => "t-digest",
			"src/A.php" => "moved",
			"src/B.php" => "b-digest",
		]));
	}

	/**
	 * A file mule cannot hash is not a file that stayed the same.
	 *
	 * @return void
	 */
	public function testIsNotReusableWhenARecordedFileIsNoLongerThere(): void {
		self::assertFalse($this->shard()->matches(["tests/AaaTest.php" => "t-digest"]));
	}

	public function testRefusesSomethingThatIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard must be an array, string given.");

		Shard::fromArray("nonsense");
	}

	public function testNamesWhicheverKeyIsMissing(): void {
		$caught = [];

		foreach(["testClass", "testIds", "lines", "testResults", "digests"] as $key) {
			$array = $this->shard()->toArray();
			unset($array[$key]);

			try {
				Shard::fromArray($array);
			} catch(Failure $failure) {
				$caught[$key] = $failure->getMessage();
			}
		}

		self::assertSame([
			"testClass" => 'A shard is missing its "testClass" key.',
			"testIds" => 'A shard is missing its "testIds" key.',
			"lines" => 'A shard is missing its "lines" key.',
			"testResults" => 'A shard is missing its "testResults" key.',
			"digests" => 'A shard is missing its "digests" key.',
		], $caught);
	}

	public function testRefusesATestClassThatIsNotAString(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A shard\'s "testClass" is not a class name.');

		Shard::fromArray([...$this->shard()->toArray(), "testClass" => 42]);
	}

	public function testRefusesAnEmptyTestClass(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A shard\'s "testClass" is not a class name.');

		Shard::fromArray([...$this->shard()->toArray(), "testClass" => ""]);
	}

	public function testNamesWhicheverSectionIsNotAnArray(): void {
		$caught = [];

		foreach(["testIds" => "test ids", "lines" => "line data", "testResults" => "test results", "digests" => "digests"] as $key => $what) {
			try {
				Shard::fromArray([...$this->shard()->toArray(), $key => 42]);
			} catch(Failure $failure) {
				$caught[$key] = $failure->getMessage();
			}
		}

		self::assertSame([
			"testIds" => "A shard's test ids must be an array, int given.",
			"lines" => "A shard's line data must be an array, int given.",
			"testResults" => "A shard's test results must be an array, int given.",
			"digests" => "A shard's digests must be an array, int given.",
		], $caught);
	}

	/**
	 * The line data holds positions in the test-id list, so a list with holes
	 * in it would silently attribute coverage to the wrong test — M3's failure
	 * mode, arriving through the cache rather than through a split.
	 *
	 * @return void
	 */
	public function testRefusesTestIdsThatAreNotAList(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's test ids must be a list, which decides what its line data indexes into.");

		Shard::fromArray([...$this->shard()->toArray(), "testIds" => [3 => "AaaTest::testOne"]]);
	}
}
