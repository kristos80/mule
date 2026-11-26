<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\ShardFormat;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;

final class ShardFormatTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function stored(array $overrides = []): array {
		return [
			"testClass" => "Demo\\AaaTest",
			"testIds" => ["Demo\\AaaTest::testOne", "Demo\\AaaTest::testTwo"],
			"lines" => ["A.php" => [1 => [0, 1], 2 => [1]]],
			"testResults" => [
				"Demo\\AaaTest::testOne" => ["size" => "u", "status" => "success", "time" => 0.1],
				"Demo\\AaaTest::testTwo" => ["size" => "u", "status" => "success", "time" => 0.2],
			],
			"digests" => ["tests/AaaTest.php" => "t", "src/A.php" => "a"],
			...$overrides,
		];
	}

	public function testReadsAWellFormedShard(): void {
		$read = ShardFormat::read($this->stored());

		self::assertSame("Demo\\AaaTest", $read["testClass"]);
		self::assertSame(["Demo\\AaaTest::testOne", "Demo\\AaaTest::testTwo"], $read["testIds"]);
		self::assertSame(["A.php" => [1 => [0, 1], 2 => [1]]], $read["lines"]);
		self::assertSame(["tests/AaaTest.php" => "t", "src/A.php" => "a"], $read["digests"]);
	}

	public function testRefusesATestIdThatIsNotAString(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's test ids include one that is not a test id.");

		ShardFormat::read($this->stored(["testIds" => ["Demo\\AaaTest::testOne", 42]]));
	}

	public function testRefusesAnEmptyTestId(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's test ids include one that is not a test id.");

		ShardFormat::read($this->stored(["testIds" => [""]]));
	}

	public function testRefusesLineDataKeyedBySomethingOtherThanAPath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's line data is keyed by something that is not a file path.");

		ShardFormat::read($this->stored(["lines" => [7 => [1 => [0]]]]));
	}

	public function testRefusesLineDataKeyedByAnEmptyPath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's line data is keyed by something that is not a file path.");

		ShardFormat::read($this->stored(["lines" => ["" => [1 => [0]]]]));
	}

	public function testRefusesALineNumberThatIsNotANumber(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A shard credits "A.php" at something that is not a line number.');

		ShardFormat::read($this->stored(["lines" => ["A.php" => ["one" => [0]]]]));
	}

	/**
	 * Lines are counted from one, so zero is as wrong as a negative — and both
	 * would place coverage on a line that cannot exist.
	 *
	 * @return void
	 */
	public function testRefusesALineNumberBelowOne(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A shard credits "A.php" at something that is not a line number.');

		ShardFormat::read($this->stored(["lines" => ["A.php" => [0 => [0]]]]));
	}

	public function testRefusesATestIndexThatIsNotAnIndex(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A shard credits "A.php" line 1 to something that is not a test index.');

		ShardFormat::read($this->stored(["lines" => ["A.php" => [1 => ["zero"]]]]));
	}

	public function testRefusesANegativeTestIndex(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A shard credits "A.php" line 1 to something that is not a test index.');

		ShardFormat::read($this->stored(["lines" => ["A.php" => [1 => [-1]]]]));
	}

	/**
	 * The indices are positions in the test-id list, so a map with holes would
	 * attribute coverage to whichever test happened to sit at that key.
	 *
	 * @return void
	 */
	public function testRefusesCoveringIndicesThatAreNotAList(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A shard\'s the tests covering "A.php" line 1 must be a list');

		ShardFormat::read($this->stored(["lines" => ["A.php" => [1 => [3 => 0]]]]));
	}

	public function testRefusesADigestUnderSomethingOtherThanAPath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's digests include one that is not a path and a digest.");

		ShardFormat::read($this->stored(["digests" => [42 => "a"]]));
	}

	public function testRefusesADigestThatIsNotAString(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's digests include one that is not a path and a digest.");

		ShardFormat::read($this->stored(["digests" => ["src/A.php" => 42]]));
	}

	/**
	 * An empty digest matches nothing, so a shard carrying one could never be
	 * reused — but it would also never say why, and the reuse rule is the one
	 * thing the record exists for.
	 *
	 * @return void
	 */
	public function testRefusesAnEmptyDigest(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's digests include one that is not a path and a digest.");

		ShardFormat::read($this->stored(["digests" => ["src/A.php" => ""]]));
	}
}
