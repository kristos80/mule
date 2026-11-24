<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Touched;
use Kristos80\Mule\Cache\Unproven;
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
			. "\t},\n\t\"watched\": {}\n}\n",
			StoreFormat::write($snapshot),
		);
	}

	public function testWritesAnEmptySnapshotAsEmptySections(): void {
		self::assertSame(
			"{\n\t\"verified\": {},\n\t\"unproven\": {},\n\t\"tests\": {},\n\t\"watched\": {}\n}\n",
			StoreFormat::write(Snapshot::of([], [])),
		);
	}

	public function testWritesWhatItWatchesAndWhereTheTestsStop(): void {
		$snapshot = Snapshot::of(
			[],
			[],
			["src/B.php" => new Unproven("bb", [new Unreached("one", 4, 9, 2)])],
			["composer.json" => "cc"],
		);

		self::assertSame(
			"{\n\t\"verified\": {},\n\t\"unproven\": {\n"
			. "\t\t\"src/B.php\": {\"digest\":\"bb\",\"unreached\":[{\"name\":\"one\",\"from\":4,\"to\":9,\"lines\":2}]}\n"
			. "\t},\n\t\"tests\": {},\n\t\"watched\": {\n"
			. "\t\t\"composer.json\": {\"digest\":\"cc\"}\n"
			. "\t}\n}\n",
			StoreFormat::write($snapshot),
		);
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
