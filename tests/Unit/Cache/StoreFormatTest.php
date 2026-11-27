<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Footprint;
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
			["tests/ATest.php" => new Footprint("sig", ["src/A.php", "src/B.php"]), "tests/BTest.php" => new Footprint("sig", ["src/B.php"])],
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
			"tests" => [
				"tests/ATest.php" => ["sig" => "sig-a", "touches" => ["src/A.php"]],
				"tests/BTest.php" => ["sig" => "sig-b", "touches" => ["src/B.php"]],
			],
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

		foreach(["toolchain", "verdicts", "unproven", "tests"] as $section) {
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
			"tests" => 'A store is missing its "tests" section.',
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

	public function testRefusesATestMapThatIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store's test map must be an array, int given.");

		StoreFormat::read($this->stored(["tests" => 42]));
	}

	public function testRefusesATestMapKeyedBySomethingOtherThanATestFile(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A store's test map is keyed by something that is not a test file.");

		StoreFormat::read($this->stored(["tests" => [42 => ["src/A.php"]]]));
	}

	public function testRefusesARecordThatIsNotAnObject(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The store\'s record for "tests/ATest.php" must be an object.');

		StoreFormat::read($this->stored(["tests" => ["tests/ATest.php" => "src/A.php"]]));
	}

	/**
	 * **The signature is what makes a test that covers nothing checkable.** Its
	 * digest is in no file's key, so a record without one is a test mule has heard
	 * of and can say nothing about.
	 *
	 * @return void
	 */
	public function testRefusesARecordWithNoSignature(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The store\'s record for "tests/ATest.php" carries no signature.');

		StoreFormat::read($this->stored(["tests" => ["tests/ATest.php" => ["touches" => []]]]));
	}

	public function testRefusesARecordWhoseTouchListIsNotOne(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A store\'s record of what "tests/ATest.php" runs must be an array, string given.');

		StoreFormat::read($this->stored([
			"tests" => ["tests/ATest.php" => ["sig" => "s", "touches" => "src/A.php"]],
		]));
	}

	/**
	 * The record is what lets a run decide it has nothing to do without
	 * generating a coverage report, so an entry that is not a path is a
	 * dependency edge pointing nowhere.
	 *
	 * @return void
	 */
	public function testRefusesARecordEntryThatIsNotAPath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A store\'s record of what "tests/ATest.php" runs includes something that is not a file path.');

		StoreFormat::read($this->stored(["tests" => ["tests/ATest.php" => ["sig" => "s", "touches" => [42]]]]));
	}

	public function testCarriesTheTestMapThroughTheRoundTrip(): void {
		$written = StoreFormat::write($this->snapshot());

		$read = StoreFormat::read(json_decode($written, TRUE, 512, JSON_THROW_ON_ERROR));

		self::assertSame(
			[
				"tests/ATest.php" => ["sig" => "sig", "touches" => ["src/A.php", "src/B.php"]],
				"tests/BTest.php" => ["sig" => "sig", "touches" => ["src/B.php"]],
			],
			array_map(static fn (Footprint $f): array => $f->toArray(), $read->tests),
		);
	}

	/**
	 * Every entry, not just the first: the record is what a run decides from,
	 * and a map read down to one test is a project with one test in it.
	 *
	 * @return void
	 */
	public function testReadsEveryEntryInTheTestMap(): void {
		self::assertSame(
			["tests/ATest.php", "tests/BTest.php"],
			array_keys(StoreFormat::read($this->stored())->tests),
		);
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
