<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Shard;
use Kristos80\Mule\Coverage\Timings;
use Kristos80\Mule\Runner\Shards;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class ShardsTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	private Shards $shards;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem();
		$this->shards = new Shards($this->filesystem, "/p/.mule/cache");
	}

	/**
	 * @param non-empty-string $class
	 * @return Shard
	 */
	private function shard(string $class = "AaaTest"): Shard {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([0 => "{$class}::testOne"]);
		$coverage->setLineCoverage(["A.php" => [3 => [0 => 1]]]);

		return Shard::of(
			$class,
			Capture::of([
				"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
				"basePath" => "/p/src",
				"codeCoverage" => $coverage,
				"testResults" => [
					"{$class}::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.5],
				],
			]),
			["tests/Unit/{$class}.php" => "aaa", "src/A.php" => "bbb"],
		);
	}

	public function testThereIsNothingBeforeAnythingIsWritten(): void {
		self::assertFalse($this->shards->has("tests/Unit/AaaTest.php"));
	}

	public function testAShardComesBackAsItWentIn(): void {
		$this->shards->write("tests/Unit/AaaTest.php", $this->shard());

		self::assertSame("AaaTest", $this->shards->read("tests/Unit/AaaTest.php")->testClass);
	}

	/**
	 * **Keyed by test file, not by test class.** The store speaks in
	 * project-relative paths and so does the tree on disk; a cache keyed by class
	 * would need the autoloader consulted before it could answer whether it holds
	 * something.
	 *
	 * @return void
	 */
	public function testOneFilePerTestFile(): void {
		$this->shards->write("tests/Unit/Deep/AaaTest.php", $this->shard());

		self::assertSame(
			["/p/.mule/cache/tests_Unit_Deep_AaaTest.php.json"],
			array_keys($this->filesystem->files),
		);
	}

	/**
	 * A cache entry that is executed is a cache entry that can run code, and these
	 * live in a working tree beside the sources they describe.
	 *
	 * @return void
	 */
	public function testAShardIsDataRatherThanCode(): void {
		$this->shards->write("tests/Unit/AaaTest.php", $this->shard());

		self::assertStringStartsWith("{", $this->filesystem->read("/p/.mule/cache/tests_Unit_AaaTest.php.json"));
	}

	/**
	 * **Machine-local, and it ignores itself.** The store beside it is committed;
	 * a real project's cache is megabytes of shards. One careless `git add .mule`
	 * is the whole difference, so mule writes the `.gitignore` rather than asking.
	 *
	 * @return void
	 */
	public function testTheCacheIgnoresItself(): void {
		$this->shards->prepare();

		self::assertSame("*\n", $this->filesystem->read("/p/.mule/cache/.gitignore"));
	}

	public function testPreparingMakesTheDirectory(): void {
		$this->shards->prepare();

		self::assertSame(["/p/.mule/cache"], $this->filesystem->directories);
	}

	public function testPreparingTwiceIsNotAProblem(): void {
		$this->shards->prepare();
		$this->shards->prepare();

		self::assertSame(["/p/.mule/cache"], $this->filesystem->directories);
	}

	public function testEveryShardAskedFor(): void {
		$this->shards->write("tests/Unit/AaaTest.php", $this->shard("AaaTest"));
		$this->shards->write("tests/Unit/BbbTest.php", $this->shard("BbbTest"));

		self::assertSame(
			["AaaTest", "BbbTest"],
			array_map(
				static fn (Shard $shard): string => $shard->testClass,
				$this->shards->all(["tests/Unit/AaaTest.php", "tests/Unit/BbbTest.php"]),
			),
		);
	}

	/**
	 * A test file with no shard is the covers-nothing case (M16): a class that
	 * executes no instrumented source is absent from `testIds()`, so splitting
	 * produces nothing for it. Harmless to merge nothing; dangerous only if it were
	 * inferred rather than passed over deliberately.
	 *
	 * @return void
	 */
	public function testATestFileWithNoShardIsPassedOver(): void {
		$this->shards->write("tests/Unit/AaaTest.php", $this->shard());

		self::assertCount(1, $this->shards->all(["tests/Unit/AaaTest.php", "tests/Unit/NothingTest.php"]));
	}

	public function testThereAreNoTimingsBeforeAnyAreWritten(): void {
		self::assertFalse($this->shards->hasTimings());
	}

	/**
	 * **Stored as the report Infection reads**, rather than as a map, so there is
	 * one spelling of it and no second encoder to disagree with the first.
	 *
	 * @return void
	 */
	public function testTimingsComeBackAsTheyWentIn(): void {
		$timings = Timings::from(
			'<?xml version="1.0"?><testsuites><testsuite name="AaaTest" file="/p/tests/Unit/AaaTest.php"'
			. ' time="1.5"/></testsuites>',
		);

		$this->shards->writeTimings($timings);

		self::assertTrue($this->shards->hasTimings());
		self::assertSame([], $this->shards->readTimings()->missing(["AaaTest"]));
	}
}
