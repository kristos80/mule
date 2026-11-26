<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Merge;
use Kristos80\Mule\Coverage\Split;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class MergeTest extends TestCase {

	/**
	 * @param list<non-empty-string> $testIds
	 * @param array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>>> $lines
	 * @return Capture
	 */
	private function shard(array $testIds, array $lines, string $basePath = "/src"): Capture {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds($testIds);
		$coverage->setLineCoverage($lines);

		$results = [];

		foreach($testIds as $id) {
			$results[$id] = ["size" => "unknown", "status" => "success", "time" => 0.5];
		}

		return Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => $basePath,
			"codeCoverage" => $coverage,
			"testResults" => $results,
		]);
	}

	/**
	 * The project-level capture a merge takes its build information from.
	 *
	 * @return Capture
	 */
	private function template(): Capture {
		return $this->shard([], []);
	}

	/**
	 * Coverage keyed by resolved test name, so shard-local indices do not
	 * confuse the comparison.
	 *
	 * @param Capture $capture
	 * @return array<string, array<int, list<string>>>
	 */
	private function byName(Capture $capture): array {
		$ids = $capture->testIds();
		$out = [];

		foreach($capture->coverage->lineCoverage() as $file => $lines) {
			$named = $this->linesByName($lines, $ids);

			if($named !== []) {
				$out[$file] = $named;
			}
		}

		return $out;
	}

	/**
	 * @param array<int<1, max>, array<int<0, max>, int<1, max>>|null> $lines
	 * @param array<int<0, max>, non-empty-string> $ids
	 * @return array<int, list<string>>
	 */
	private function linesByName(array $lines, array $ids): array {
		$out = [];

		foreach($lines as $line => $tests) {
			if(!is_array($tests) || $tests === []) {
				continue;
			}

			$names = array_map(static fn(int $index): string => $ids[$index], array_keys($tests));
			sort($names);
			$out[$line] = $names;
		}

		return $out;
	}

	public function testUnionsCoverageFromEveryShard(): void {
		$merged = new Merge($this->template(), [
			$this->shard(["AaaTest::testOne"], ["/src/A.php" => [1 => [0 => 1]]]),
			$this->shard(["BbbTest::testTwo"], ["/src/B.php" => [7 => [0 => 1]]]),
		])->into("/src");

		self::assertSame([
			"/src/A.php" => [1 => ["AaaTest::testOne"]],
			"/src/B.php" => [7 => ["BbbTest::testTwo"]],
		], $this->byName($merged));
	}

	/**
	 * The case the whole cache rests on: two shards naming the same line, each
	 * with its own local index 0, have to come out as two distinct tests.
	 *
	 * @return void
	 */
	public function testTwoShardsCoveringOneLineBothSurviveTheIndexRemapping(): void {
		$merged = new Merge($this->template(), [
			$this->shard(["AaaTest::testOne"], ["/src/A.php" => [1 => [0 => 1]]]),
			$this->shard(["BbbTest::testTwo"], ["/src/A.php" => [1 => [0 => 1]]]),
		])->into("/src");

		self::assertSame(
			["/src/A.php" => [1 => ["AaaTest::testOne", "BbbTest::testTwo"]]],
			$this->byName($merged),
		);
	}

	public function testUnionsTestResults(): void {
		$merged = new Merge($this->template(), [
			$this->shard(["AaaTest::testOne"], ["/src/A.php" => [1 => [0 => 1]]]),
			$this->shard(["BbbTest::testTwo"], ["/src/B.php" => [7 => [0 => 1]]]),
		])->into("/src");

		self::assertSame([
			"AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.5],
			"BbbTest::testTwo" => ["size" => "unknown", "status" => "success", "time" => 0.5],
		], $merged->testResults);
	}

	/**
	 * A warm run reassembles fresh shards beside cached ones, and which is
	 * which depends on the edit rather than on the project. The result must
	 * not.
	 *
	 * @return void
	 */
	public function testTheResultDoesNotDependOnTheOrderTheShardsArriveIn(): void {
		$a = $this->shard(["ZzzTest::testOne"], ["/src/Z.php" => [1 => [0 => 1]]]);
		$b = $this->shard(["AaaTest::testTwo"], ["/src/A.php" => [7 => [0 => 1]]]);

		$forwards = new Merge($this->template(), [$a, $b])->into("/src");
		$backwards = new Merge($this->template(), [$b, $a])->into("/src");

		self::assertSame(array_keys($forwards->testResults), array_keys($backwards->testResults));
		self::assertSame(["AaaTest::testTwo", "ZzzTest::testOne"], array_keys($forwards->testResults));
	}

	/**
	 * M9: the base path decides what every path in the rendered report is
	 * relative to, and a partial shard set would derive a deeper one.
	 *
	 * @return void
	 */
	public function testTheBasePathIsTakenFromTheArgumentNotFromTheShards(): void {
		$merged = new Merge($this->template(), [
			$this->shard(["AaaTest::testOne"], ["/src/Deep/A.php" => [1 => [0 => 1]]], "/src/Deep"),
		])->into("/src");

		self::assertSame("/src", $merged->basePath);
	}

	public function testBuildInformationComesFromTheTemplateNotFromAShard(): void {
		$template = Capture::of([
			"buildInformation" => [
				"phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT],
				"timestamp" => "the project's",
			],
			"basePath" => "/src",
			"codeCoverage" => new ProcessedCodeCoverageData(),
			"testResults" => [],
		]);
		$shard = $this->shard(["AaaTest::testOne"], ["/src/A.php" => [1 => [0 => 1]]]);

		$merged = new Merge($template, [$shard])->into("/src");

		self::assertSame($template->buildInformation, $merged->buildInformation);
		self::assertNotSame($shard->buildInformation, $merged->buildInformation);
	}

	public function testRefusesAnEmptyShardSet(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("There is nothing to merge: no coverage shards were given.");

		new Merge($this->template(), [])->into("/src");
	}

	/**
	 * A stale shard kept beside the fresh one that replaced it. Letting one win
	 * silently would make the result depend on iteration order.
	 *
	 * @return void
	 */
	public function testRefusesATestClaimedByTwoShards(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The test "AaaTest::testOne" is claimed by more than one coverage shard.');

		new Merge($this->template(), [
			$this->shard(["AaaTest::testOne"], ["/src/A.php" => [1 => [0 => 1]]]),
			$this->shard(["AaaTest::testOne"], ["/src/A.php" => [2 => [0 => 1]]]),
		])->into("/src");
	}

	/**
	 * The round trip, which is the property the cache depends on: splitting a
	 * capture per test class and merging the pieces gives the capture back.
	 * Verified at scale on a 613-file project; pinned here on a fixture.
	 *
	 * @return void
	 */
	public function testSplittingAndMergingIsTheIdentity(): void {
		$whole = $this->shard(
			["AaaTest::testOne", "BbbTest::testTwo", "AaaTest::testThree"],
			[
				"/src/A.php" => [1 => [0 => 1, 2 => 1], 2 => [1 => 1]],
				"/src/B.php" => [7 => [1 => 1]],
			],
		);

		$merged = new Merge($whole, new Split($whole)->byTestClass())->into($whole->basePath);

		self::assertSame($this->byName($whole), $this->byName($merged));

		// the merge sorts, so compare the sets rather than the orders
		$expected = $whole->testResults;
		ksort($expected);
		self::assertSame($expected, $merged->testResults);
	}
}
