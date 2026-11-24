<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Touched;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SnapshotTest extends TestCase {

	public function testCountsAndNamesWhatItHoldsSortedWhateverOrderItArrivedIn(): void {
		$snapshot = Snapshot::of(["src/B.php" => "bb", "src/A.php" => "aa"], []);

		self::assertSame(2, $snapshot->count());
		self::assertSame(["src/A.php", "src/B.php"], $snapshot->paths());
	}

	public function testHoldsNothingWhenNothingWasProven(): void {
		self::assertSame(0, Snapshot::of([], [])->count());
	}

	public function testFindsEveryFileTheStoreHasNeverHeardOf(): void {
		$current = self::snapshot(["src/A.php" => "aa", "src/B.php" => "bb"]);

		self::assertSame(["src/A.php", "src/B.php"], $current->staleAgainst(Snapshot::of([], [])));
	}

	public function testFindsNothingStaleWhenTheStoreAgreesAboutEverything(): void {
		$current = self::snapshot(["src/A.php" => "aa", "src/B.php" => "bb"]);

		self::assertSame([], $current->staleAgainst(self::snapshot(["src/A.php" => "aa", "src/B.php" => "bb"])));
	}

	public function testFindsTheFileWhoseOwnBytesChanged(): void {
		$current = self::snapshot(["src/A.php" => "aa", "src/B.php" => "changed"]);

		self::assertSame(["src/B.php"], $current->staleAgainst(self::snapshot(["src/A.php" => "aa", "src/B.php" => "bb"])));
	}

	public function testFindsTheFileWhoseTestChanged(): void {
		$current = Snapshot::of(
			["src/A.php" => "aa"],
			["tests/ATest.php" => new Touched("changed", ["src/A.php"])],
		);
		$stored = Snapshot::of(
			["src/A.php" => "aa"],
			["tests/ATest.php" => new Touched("tt", ["src/A.php"])],
		);

		self::assertSame(["src/A.php"], $current->staleAgainst($stored));
	}

	/**
	 * The reason the store keeps a list rather than a digest: `src/B.php` is
	 * untouched and so is its test, but the test runs `src/A.php` too, and a
	 * mutant in B lives or dies by what A does.
	 *
	 * @return void
	 */
	public function testFindsTheFileWhoseTestRunsSomethingElseThatChanged(): void {
		$tests = ["tests/BothTest.php" => new Touched("tt", ["src/A.php", "src/B.php"])];
		$current = Snapshot::of(["src/A.php" => "changed", "src/B.php" => "bb"], $tests);
		$stored = Snapshot::of(["src/A.php" => "aa", "src/B.php" => "bb"], $tests);

		self::assertSame(["src/A.php", "src/B.php"], $current->staleAgainst($stored));
	}

	public function testLeavesAloneAFileWhoseTestRunsNothingThatChanged(): void {
		$tests = [
			"tests/ATest.php" => new Touched("tt", ["src/A.php"]),
			"tests/BTest.php" => new Touched("uu", ["src/B.php"]),
		];
		$current = Snapshot::of(["src/A.php" => "changed", "src/B.php" => "bb"], $tests);
		$stored = Snapshot::of(["src/A.php" => "aa", "src/B.php" => "bb"], $tests);

		self::assertSame(["src/A.php"], $current->staleAgainst($stored));
	}

	public function testIgnoresAStoredFileTheReportNoLongerKnowsAbout(): void {
		$current = self::snapshot(["src/A.php" => "aa"]);

		self::assertSame([], $current->staleAgainst(self::snapshot(["src/A.php" => "aa", "src/Gone.php" => "gg"])));
	}

	public function testIgnoresATestRunningAFileTheReportNoLongerKnowsAbout(): void {
		$current = Snapshot::of(
			["src/A.php" => "aa"],
			["tests/ATest.php" => new Touched("changed", ["src/A.php", "src/Gone.php"])],
		);
		$stored = Snapshot::of(
			["src/A.php" => "aa"],
			["tests/ATest.php" => new Touched("tt", ["src/A.php", "src/Gone.php"])],
		);

		self::assertSame(["src/A.php"], $current->staleAgainst($stored));
	}

	public function testNamesWhichOfThePathsGivenItHoldsAVerdictFor(): void {
		$snapshot = self::snapshot(["src/A.php" => "aa", "src/B.php" => "bb", "src/C.php" => "cc"]);

		self::assertSame(["src/A.php", "src/C.php"], $snapshot->knownOf(["src/A.php", "src/Gone.php", "src/C.php"]));
	}

	public function testKeepsThePathsInTheOrderItWasGivenThem(): void {
		$snapshot = self::snapshot(["src/A.php" => "aa", "src/B.php" => "bb"]);

		self::assertSame(["src/B.php", "src/A.php"], $snapshot->knownOf(["src/B.php", "src/A.php"]));
	}

	public function testNamesNoneOfThemWhenItHoldsNoneOfThem(): void {
		self::assertSame([], self::snapshot(["src/A.php" => "aa"])->knownOf(["src/Gone.php"]));
	}

	public function testWritesAnotherSnapshotOverItsOwn(): void {
		$merged = self::snapshot(["src/A.php" => "old", "src/B.php" => "bb"])
			->merged(self::snapshot(["src/A.php" => "new"]));

		self::assertStringContainsString('"src/A.php": {"digest":"new"}', $merged->toJson());
		self::assertSame(["src/A.php", "src/B.php"], $merged->paths());
	}

	public function testKeepsATestTheOtherSnapshotDoesNotHold(): void {
		$mine = Snapshot::of([], ["tests/ATest.php" => new Touched("tt", ["src/A.php"])]);
		$merged = $mine->merged(Snapshot::of([], ["tests/BTest.php" => new Touched("uu", ["src/B.php"])]));

		self::assertStringContainsString("tests/ATest.php", $merged->toJson());
		self::assertStringContainsString("tests/BTest.php", $merged->toJson());
	}

	public function testNarrowsToTheNamedFilesAndTheTestsThatRunThem(): void {
		$snapshot = Snapshot::of(["src/A.php" => "aa", "src/B.php" => "bb"], [
			"tests/ATest.php" => new Touched("tt", ["src/A.php"]),
			"tests/BTest.php" => new Touched("uu", ["src/B.php"]),
		]);

		$only = $snapshot->only(["src/A.php"]);

		self::assertSame(["src/A.php"], $only->paths());
		self::assertStringContainsString("tests/ATest.php", $only->toJson());
		self::assertStringNotContainsString("tests/BTest.php", $only->toJson());
	}

	public function testRejectsNarrowingToAPathItDoesNotHold(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("src/Gone.php is not in this run's verdicts, so mule cannot bank it.");

		self::snapshot(["src/A.php" => "aa"])->only(["src/Gone.php"]);
	}

	public function testWritesItselfSortedOneEntryPerLine(): void {
		$snapshot = Snapshot::of(["src/B.php" => "bb", "src/A.php" => "aa"], [
			"tests/BTest.php" => new Touched("uu", ["src/B.php"]),
			"tests/ATest.php" => new Touched("tt", ["src/A.php"]),
		]);

		self::assertSame(
			"{\n\t\"verified\": {\n"
			. "\t\t\"src/A.php\": {\"digest\":\"aa\"},\n"
			. "\t\t\"src/B.php\": {\"digest\":\"bb\"}\n"
			. "\t},\n\t\"tests\": {\n"
			. "\t\t\"tests/ATest.php\": {\"source\":\"tt\",\"touches\":[\"src/A.php\"]},\n"
			. "\t\t\"tests/BTest.php\": {\"source\":\"uu\",\"touches\":[\"src/B.php\"]}\n"
			. "\t}\n}\n",
			$snapshot->toJson(),
		);
	}

	/**
	 * The order the files turn up in is the order a test happens to list them,
	 * which is not an order anything downstream should inherit.
	 *
	 * @return void
	 */
	public function testNamesTheStaleFilesSortedWhicheverWayTheyWereFound(): void {
		$tests = ["tests/BothTest.php" => new Touched("tt", ["src/B.php", "src/A.php"])];
		$current = Snapshot::of(["src/A.php" => "aa", "src/B.php" => "bb"], $tests);
		$stored = Snapshot::of(["src/A.php" => "aa", "src/B.php" => "bb"], [
			"tests/BothTest.php" => new Touched("changed", ["src/B.php", "src/A.php"]),
		]);

		self::assertSame(["src/A.php", "src/B.php"], $current->staleAgainst($stored));
	}

	public function testWritesAnEmptySnapshotAsEmptySections(): void {
		self::assertSame("{\n\t\"verified\": {},\n\t\"tests\": {}\n}\n", Snapshot::of([], [])->toJson());
	}

	public function testReadsBackWhatItWrote(): void {
		$snapshot = Snapshot::of(["src/B.php" => "bb", "src/A.php" => "aa"], [
			"tests/ATest.php" => new Touched("tt", ["src/A.php", "src/B.php"]),
		]);

		self::assertSame($snapshot->toJson(), Snapshot::fromJson($snapshot->toJson())->toJson());
	}

	public function testReadsAStoreWrittenBeforeTheseSectionsExistedAsEmpty(): void {
		$old = '{"src/A.php": {"source": "aa", "tests": "tt"}}';

		self::assertSame(0, Snapshot::fromJson($old)->count());
	}

	public function testRejectsAStoreEntryMissingItsDigest(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The digest in the mule store verified entry for src/A.php must be a non-empty string.");

		Snapshot::fromJson('{"verified": {"src/A.php": {}}}');
	}

	public function testRejectsASectionThatIsNotAnObject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The verified in the mule store must be an object.");

		Snapshot::fromJson('{"verified": "src/A.php"}');
	}

	/**
	 * @param array<string, string> $verified
	 * @return Snapshot
	 */
	private static function snapshot(array $verified): Snapshot {
		return Snapshot::of($verified, []);
	}
}
