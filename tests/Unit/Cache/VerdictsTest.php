<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Verdict;
use Kristos80\Mule\Cache\Verdicts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VerdictsTest extends TestCase {

	public function testCountsWhatItHolds(): void {
		self::assertSame(2, self::given(["src/B.php", "src/A.php"])->count());
		self::assertSame(0, Verdicts::of([])->count());
	}

	public function testNamesItsPathsSortedWhateverOrderTheyArrivedIn(): void {
		self::assertSame(["src/A.php", "src/B.php"], self::given(["src/B.php", "src/A.php"])->paths());
	}

	public function testFindsEveryFileTheStoreHasNeverHeardOf(): void {
		$current = self::given(["src/A.php", "src/B.php"]);

		self::assertSame(["src/A.php", "src/B.php"], $current->staleAgainst(Verdicts::of([])));
	}

	public function testFindsNothingStaleWhenTheStoreAgreesAboutEveryFile(): void {
		$current = self::given(["src/A.php", "src/B.php"]);

		self::assertSame([], $current->staleAgainst(self::given(["src/A.php", "src/B.php"])));
	}

	public function testFindsTheFileWhoseSourceHasChanged(): void {
		$current = self::given(["src/A.php", "src/B.php"]);
		$stored = Verdicts::of([
			"src/A.php" => new Verdict("source-of-src/A.php", "tests-of-src/A.php"),
			"src/B.php" => new Verdict("something else", "tests-of-src/B.php"),
		]);

		self::assertSame(["src/B.php"], $current->staleAgainst($stored));
	}

	public function testFindsTheFileWhoseCoveringTestsHaveChanged(): void {
		$current = self::given(["src/A.php"]);
		$stored = Verdicts::of(["src/A.php" => new Verdict("source-of-src/A.php", "something else")]);

		self::assertSame(["src/A.php"], $current->staleAgainst($stored));
	}

	public function testIgnoresAStoredFileTheReportNoLongerKnowsAbout(): void {
		$current = self::given(["src/A.php"]);

		self::assertSame([], $current->staleAgainst(self::given(["src/A.php", "src/Gone.php"])));
	}

	public function testNamesWhichOfThePathsGivenItHoldsAVerdictFor(): void {
		$verdicts = self::given(["src/A.php", "src/B.php", "src/C.php"]);

		self::assertSame(["src/A.php", "src/C.php"], $verdicts->knownOf(["src/A.php", "src/Gone.php", "src/C.php"]));
	}

	public function testKeepsThePathsInTheOrderItWasGivenThem(): void {
		$verdicts = self::given(["src/A.php", "src/B.php"]);

		self::assertSame(["src/B.php", "src/A.php"], $verdicts->knownOf(["src/B.php", "src/A.php"]));
	}

	public function testNamesNoneOfThemWhenItHoldsNoneOfThem(): void {
		self::assertSame([], self::given(["src/A.php"])->knownOf(["src/Gone.php"]));
	}

	public function testWritesAnotherSetOverItsOwn(): void {
		$banked = Verdicts::of(["src/A.php" => new Verdict("new", "new")]);
		$merged = self::given(["src/A.php", "src/B.php"])->merged($banked);

		self::assertSame(["src/A.php", "src/B.php"], $merged->paths());
		self::assertStringContainsString('"src/A.php": {"source":"new","tests":"new"}', $merged->toJson());
	}

	public function testKeepsAnEntryTheOtherSetDoesNotHold(): void {
		$merged = self::given(["src/A.php"])->merged(Verdicts::of(["src/B.php" => new Verdict("bb", "bb")]));

		self::assertSame(["src/A.php", "src/B.php"], $merged->paths());
	}

	public function testNarrowsToTheNamedFiles(): void {
		$only = self::given(["src/A.php", "src/B.php", "src/C.php"])->only(["src/C.php", "src/A.php"]);

		self::assertSame(["src/A.php", "src/C.php"], $only->paths());
	}

	public function testRejectsNarrowingToAPathItDoesNotHold(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("src/Gone.php is not in this run's verdicts, so mule cannot bank it.");

		self::given(["src/A.php"])->only(["src/Gone.php"]);
	}

	public function testWritesItselfSortedOneEntryPerLineWithSlashesAsTheyWereWritten(): void {
		$verdicts = Verdicts::of([
			"src/B.php" => new Verdict("bb", "22"),
			"src/A.php" => new Verdict("aa", "11"),
		]);

		self::assertSame(
			"{\n\t\"src/A.php\": {\"source\":\"aa\",\"tests\":\"11\"},\n\t\"src/B.php\": {\"source\":\"bb\",\"tests\":\"22\"}\n}\n",
			$verdicts->toJson(),
		);
	}

	public function testWritesAnEmptySetAsAnEmptyObject(): void {
		self::assertSame("{}\n", Verdicts::of([])->toJson());
	}

	public function testReadsBackWhatItWrote(): void {
		$verdicts = self::given(["src/B.php", "src/A.php"]);

		self::assertSame($verdicts->toJson(), Verdicts::fromJson($verdicts->toJson())->toJson());
	}

	public function testReadsAStoreWithNoEntries(): void {
		self::assertSame(0, Verdicts::fromJson("{}")->count());
	}

	public function testRejectsAStoreEntryMissingItsSourceDigest(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The source in the mule store entry for src/A.php must be a non-empty string.");

		Verdicts::fromJson('{"src/A.php": {"tests": "bb"}}');
	}

	public function testRejectsAStoreEntryMissingItsTestsDigest(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The tests in the mule store entry for src/A.php must be a non-empty string.");

		Verdicts::fromJson('{"src/A.php": {"source": "aa"}}');
	}

	/**
	 * A set whose digests are derived from the paths, so that a test can state
	 * which files it is talking about and nothing else.
	 *
	 * @param list<string> $paths
	 * @return Verdicts
	 */
	private static function given(array $paths): Verdicts {
		$verdicts = [];

		foreach($paths as $path) {
			$verdicts[$path] = new Verdict("source-of-{$path}", "tests-of-{$path}");
		}

		return Verdicts::of($verdicts);
	}
}
