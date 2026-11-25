<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Touched;
use Kristos80\Mule\Cache\Unproven;
use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Coverage\Unreached;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoreFormatTest extends TestCase {

	public function testWritesItselfSortedOneEntryPerLine(): void {
		$snapshot = Snapshot::of(["src/B.php" => "bb", "src/A.php" => "aa"], [
			"tests/BTest.php" => new Touched("uu", ["src/B.php"]),
			"tests/ATest.php" => new Touched("tt", ["src/A.php"]),
		]);

		self::assertSame(
			"{\n\t\"verified\": {\n"
			. "\t\t\"src/A.php\": {\"digest\":\"aa\"},\n"
			. "\t\t\"src/B.php\": {\"digest\":\"bb\"}\n"
			. "\t},\n\t\"unproven\": {},\n\t\"tests\": {\n"
			. "\t\t\"tests/ATest.php\": {\"source\":\"tt\",\"touches\":[\"src/A.php\"]},\n"
			. "\t\t\"tests/BTest.php\": {\"source\":\"uu\",\"touches\":[\"src/B.php\"]}\n"
			. "\t},\n\t\"watched\": {},\n\t\"configuration\": {},\n\t\"coverage\": {}\n}\n",
			StoreFormat::write($snapshot),
		);
	}

	public function testWritesAnEmptySnapshotAsEmptySections(): void {
		self::assertSame(
			"{\n\t\"verified\": {},\n\t\"unproven\": {},\n\t\"tests\": {},"
			. "\n\t\"watched\": {},\n\t\"configuration\": {},\n\t\"coverage\": {}\n}\n",
			StoreFormat::write(Snapshot::of([], [])),
		);
	}

	public function testWritesWhatItWatchesAndWhereTheTestsStop(): void {
		$snapshot = Snapshot::of(
			[],
			[],
			["src/B.php" => new Unproven("bb", [new Unreached("one", 4, 9, 2)])],
			["src/Excluded.php" => "ee"],
			["composer.json" => "cc"],
		);

		self::assertSame(
			"{\n\t\"verified\": {},\n\t\"unproven\": {\n"
			. "\t\t\"src/B.php\": {\"digest\":\"bb\",\"unreached\":[{\"name\":\"one\",\"from\":4,\"to\":9,\"lines\":2}]}\n"
			. "\t},\n\t\"tests\": {},\n\t\"watched\": {\n"
			. "\t\t\"src/Excluded.php\": {\"digest\":\"ee\"}\n"
			. "\t},\n\t\"configuration\": {\n"
			. "\t\t\"composer.json\": {\"digest\":\"cc\"}\n"
			. "\t},\n\t\"coverage\": {}\n}\n",
			StoreFormat::write($snapshot),
		);
	}

	/**
	 * Sorted like every other section, and it is the one section whose source
	 * is not already in path order: the configuration files are recorded in the
	 * order mule looks for them, which puts `infection.json5` before
	 * `infection.json`.
	 *
	 * @return void
	 */
	public function testWritesTheConfigurationInPathOrder(): void {
		$snapshot = Snapshot::of([], [], [], [], [
			"infection.json5" => "55",
			"infection.json" => "jj",
			"composer.json" => "cc",
		]);

		self::assertSame(
			"{\n\t\"verified\": {},\n\t\"unproven\": {},\n\t\"tests\": {},\n\t\"watched\": {},"
			. "\n\t\"configuration\": {\n"
			. "\t\t\"composer.json\": {\"digest\":\"cc\"},\n"
			. "\t\t\"infection.json\": {\"digest\":\"jj\"},\n"
			. "\t\t\"infection.json5\": {\"digest\":\"55\"}\n"
			. "\t},\n\t\"coverage\": {}\n}\n",
			StoreFormat::write($snapshot),
		);
	}

	/**
	 * A store written before the two sections were told apart kept the
	 * configuration under `watched`, and merging into it must not leave the
	 * same path in both.
	 *
	 * @return void
	 */
	public function testReadsBackAStoreThatWatchedItsOwnConfiguration(): void {
		$snapshot = StoreFormat::read(
			"{\"verified\": {\"src/A.php\": {\"digest\": \"aa\"}},"
			. "\"watched\": {\"composer.json\": {\"digest\": \"cc\"}},"
			. "\"configuration\": {\"composer.json\": {\"digest\": \"cc\"}}}",
		);

		self::assertSame([], $snapshot->watched());
		self::assertSame(["composer.json" => "cc"], $snapshot->configuration());
	}

	public function testReadsBackEveryGapRatherThanTheFirst(): void {
		$snapshot = Snapshot::of([], [], [
			"src/A.php" => new Unproven("aa", [new Unreached("one", 3, 4, 1)]),
			"src/Z.php" => new Unproven("zz", [new Unreached("two", 1, 2, 1)]),
		], []);

		self::assertSame(["src/A.php", "src/Z.php"], array_keys(StoreFormat::read(StoreFormat::write($snapshot))->gaps()));
	}

	public function testReadsBackTheGapsAndTheWatchedFiles(): void {
		$snapshot = Snapshot::of(
			["src/A.php" => "aa"],
			[],
			["src/B.php" => new Unproven("bb", [new Unreached("one", 4, 9, 2)])],
			["composer.json" => "cc"],
		);

		$read = StoreFormat::read(StoreFormat::write($snapshot));

		self::assertSame(StoreFormat::write($snapshot), StoreFormat::write($read));
		self::assertSame("one() lines 4-9, 2 unreached", $read->gaps()["src/B.php"][0]->described());
		self::assertSame(
			["composer.json" => "cc", "src/A.php" => "aa", "src/B.php" => "bb"],
			$read->digests(),
		);
	}

	public function testWritesHowMuchOfEachFileTheTestsReached(): void {
		$snapshot = Snapshot::of(["src/A.php" => "aa"], [], [], [], [], [
			"src/B.php" => new Reach(6, 2),
			"src/A.php" => new Reach(4, 4),
		]);

		self::assertStringContainsString(
			"\"coverage\": {\n"
			. "\t\t\"src/A.php\": {\"executable\":4,\"executed\":4},\n"
			. "\t\t\"src/B.php\": {\"executable\":6,\"executed\":2}\n"
			. "\t}",
			StoreFormat::write($snapshot),
		);
	}

	public function testReadsBackHowMuchOfEachFileTheTestsReached(): void {
		$snapshot = Snapshot::of([], [], [], [], [], ["src/A.php" => new Reach(9, 5)]);

		$read = StoreFormat::read(StoreFormat::write($snapshot));

		self::assertSame(9, $read->reach()->executable);
		self::assertSame(5, $read->reach()->executed);
	}

	/**
	 * Nothing to report rather than nothing to work from: the file counts in
	 * such a store are still true, and re-measuring for a number that is only
	 * ever printed would spend the run this cache exists to skip.
	 *
	 * @return void
	 */
	public function testReadsAStoreWrittenBeforeCoverageWasRecordedAsHavingNone(): void {
		$read = StoreFormat::read('{"verified": {"src/A.php": {"digest": "aa"}}}');

		self::assertSame(1, $read->count());
		self::assertFalse($read->reach()->isMeasured());
	}

	public function testRejectsACoverageEntryWithoutItsCounts(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The executable in the mule store coverage entry for src/A.php must be a whole number.");

		StoreFormat::read('{"coverage": {"src/A.php": {"executed": 4}}}');
	}

	public function testReadsBackWhatItWrote(): void {
		$snapshot = Snapshot::of(["src/B.php" => "bb", "src/A.php" => "aa"], [
			"tests/ATest.php" => new Touched("tt", ["src/A.php", "src/B.php"]),
		]);

		self::assertSame(StoreFormat::write($snapshot), StoreFormat::write(StoreFormat::read(StoreFormat::write($snapshot))));
	}

	public function testReadsAStoreWrittenBeforeTheseSectionsExistedAsEmpty(): void {
		$old = '{"src/A.php": {"source": "aa", "tests": "tt"}}';

		self::assertSame(0, StoreFormat::read($old)->count());
	}

	public function testRejectsAStoreEntryMissingItsDigest(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The digest in the mule store verified entry for src/A.php must be a non-empty string.");

		StoreFormat::read('{"verified": {"src/A.php": {}}}');
	}

	public function testRejectsASectionThatIsNotAnObject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The verified in the mule store must be an object.");

		StoreFormat::read('{"verified": "src/A.php"}');
	}
}
