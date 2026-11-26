<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Verdict;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;

final class StoreFormatTest extends TestCase {

	/**
	 * @return Snapshot
	 */
	private function snapshot(): Snapshot {
		return new Snapshot(
			["src/A.php" => new Verdict("key-a", 4), "src/B.php" => new Verdict("key-b", 0)],
			["src/C.php"],
			"chain",
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function stored(array $overrides = []): array {
		return [
			"toolchain" => "chain",
			"verdicts" => ["src/A.php" => ["key" => "key-a", "mutants" => 4]],
			"unproven" => ["src/C.php"],
			...$overrides,
		];
	}

	public function testSurvivesTheRoundTrip(): void {
		$written = StoreFormat::write($this->snapshot());
		$read = StoreFormat::read(json_decode($written, TRUE, 512, JSON_THROW_ON_ERROR));

		self::assertSame(StoreFormat::write($this->snapshot()), StoreFormat::write($read));
	}

	/**
	 * Committed and reviewed, so it is readable in a diff and ends in a newline
	 * like every other text file.
	 *
	 * @return void
	 */
	public function testIsWrittenToBeReadByAPerson(): void {
		$written = StoreFormat::write($this->snapshot());

		self::assertStringContainsString("\n    \"verdicts\"", $written);
		self::assertStringContainsString("src/A.php", $written);
		self::assertStringEndsWith("\n", $written);
	}

	public function testRefusesSomethingThatIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store must be an array, string given.");

		StoreFormat::read("nonsense");
	}

	public function testNamesWhicheverSectionIsMissing(): void {
		$caught = [];

		foreach(["toolchain", "verdicts", "unproven"] as $section) {
			$stored = $this->stored();
			unset($stored[$section]);

			try {
				StoreFormat::read($stored);
			} catch(Failure $failure) {
				$caught[$section] = $failure->getMessage();
			}
		}

		self::assertSame([
			"toolchain" => 'A store is missing its "toolchain" section.',
			"verdicts" => 'A store is missing its "verdicts" section.',
			"unproven" => 'A store is missing its "unproven" section.',
		], $caught);
	}

	/**
	 * Without a toolchain nothing in the store can be placed: every verdict in
	 * it was proven under *some* environment, and a store that cannot say which
	 * cannot be compared against this one.
	 *
	 * @return void
	 */
	public function testRefusesAStoreWithNoToolchain(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store's toolchain is not a digest, so nothing in it can be placed.");

		StoreFormat::read($this->stored(["toolchain" => ""]));
	}

	public function testRefusesVerdictsThatAreNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store's verdicts must be an array, int given.");

		StoreFormat::read($this->stored(["verdicts" => 42]));
	}

	public function testRefusesAVerdictUnderSomethingOtherThanAPath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store has a verdict under something that is not a file path.");

		StoreFormat::read($this->stored(["verdicts" => [42 => ["key" => "k", "mutants" => 1]]]));
	}

	public function testRefusesAVerdictThatIsNotAKeyAndACount(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The verdict for "src/A.php" is not a key and a count.');

		StoreFormat::read($this->stored(["verdicts" => ["src/A.php" => "proven"]]));
	}

	/**
	 * The dangerous shape rather than a malformed one: a verdict with no key
	 * would hold against anything, so every file would read as proven for ever.
	 *
	 * @return void
	 */
	public function testRefusesAVerdictWithNoKey(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The verdict for "src/A.php" rests on no key, so it would hold against anything.');

		StoreFormat::read($this->stored(["verdicts" => ["src/A.php" => ["key" => "", "mutants" => 1]]]));
	}

	public function testRefusesAMutantCountThatIsNotACount(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The verdict for "src/A.php" counts something other than mutants.');

		StoreFormat::read($this->stored(["verdicts" => ["src/A.php" => ["key" => "k", "mutants" => -1]]]));
	}

	public function testRefusesAnUnprovenListThatIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store's unproven list must be an array, string given.");

		StoreFormat::read($this->stored(["unproven" => "src/C.php"]));
	}

	public function testRefusesAnUnprovenEntryThatIsNotAPath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store's unproven list includes something that is not a file path.");

		StoreFormat::read($this->stored(["unproven" => [42]]));
	}

	/**
	 * Sorted on the way in as well as out, so a store hand-edited into a
	 * different order does not read as a different store.
	 *
	 * @return void
	 */
	public function testIsCanonicalWhateverOrderItArrivesIn(): void {
		$read = StoreFormat::read($this->stored([
			"verdicts" => [
				"src/Z.php" => ["key" => "z", "mutants" => 1],
				"src/A.php" => ["key" => "a", "mutants" => 1],
			],
			"unproven" => ["src/Z.php", "src/A.php"],
		]));

		self::assertSame(["src/A.php", "src/Z.php"], array_keys($read->verdicts));
		self::assertSame(["src/A.php", "src/Z.php"], $read->unproven);
	}
}
