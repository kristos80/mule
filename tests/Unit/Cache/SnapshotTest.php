<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Verdict;
use PHPUnit\Framework\TestCase;

final class SnapshotTest extends TestCase {

	/**
	 * @param array<string, int<0, max>> $proven Path to mutant count; the key is derived from the path.
	 * @param list<string> $unproven
	 * @param array<string, Footprint> $tests
	 * @return Snapshot
	 */
	private function snapshot(
		array $proven = [],
		array $unproven = [],
		string $toolchain = "chain",
		array $tests = [],
	): Snapshot {
		/** @var non-empty-string $toolchain */
		$verdicts = [];

		foreach($proven as $path => $mutants) {
			$verdicts[$path] = new Verdict("key-of-{$path}", $mutants);
		}

		return new Snapshot($verdicts, $unproven, $tests, $toolchain);
	}

	/**
	 * A store whose sections overlap the way a real one does: two tests sharing
	 * a hub file, verdicts for files no test record mentions, and everything
	 * handed over in the wrong order.
	 *
	 * @return Snapshot
	 */
	private function rich(): Snapshot {
		return $this->snapshot(
			["src/V2.php" => 1, "src/V1.php" => 2],
			["src/U.php"],
			"chain",
			[
				"tests/ZzzTest.php" => new Footprint("sig", ["src/Zzz.php", "src/Hub.php"]),
				"tests/AaaTest.php" => new Footprint("sig", ["src/Hub.php", "src/Aaa.php"]),
			],
		);
	}

	public function testTheTestRecordIsCanonicallyOrdered(): void {
		self::assertSame(["tests/AaaTest.php", "tests/ZzzTest.php"], array_keys($this->rich()->tests));
	}

	/**
	 * Both of them, which is the case that matters: a hub file covered by
	 * several classes is how a change reaches files that never mention it.
	 *
	 * @return void
	 */
	public function testNamesEveryTestCoveringAFile(): void {
		self::assertSame(
			["tests/AaaTest.php", "tests/ZzzTest.php"],
			$this->rich()->coveredBy("src/Hub.php"),
		);
	}

	public function testNamesNoTestForAFileNothingCovers(): void {
		self::assertSame([], $this->rich()->coveredBy("src/V1.php"));
	}

	/**
	 * The union, de-duplicated and sorted: `Hub.php` arrives from both tests,
	 * and the raw order is neither.
	 *
	 * @return void
	 */
	public function testTheDependenciesAreTheUnionOfWhatTheCoveringTestsRun(): void {
		self::assertSame(
			["src/Aaa.php", "src/Hub.php", "src/Zzz.php"],
			$this->rich()->dependenciesOf("src/Hub.php"),
		);
	}

	public function testAFileNothingCoversDependsOnNothing(): void {
		self::assertSame([], $this->rich()->dependenciesOf("src/V1.php"));
	}

	/**
	 * Every section contributes, which is what makes "a file the store has
	 * never heard of" answerable: verdicts, the unproven list, the test files
	 * themselves, and everything those tests were seen to run.
	 *
	 * @return void
	 */
	public function testKnowsEveryPathAnySectionMentions(): void {
		self::assertSame([
			"src/Aaa.php",
			"src/Hub.php",
			"src/U.php",
			"src/V1.php",
			"src/V2.php",
			"src/Zzz.php",
			"tests/AaaTest.php",
			"tests/ZzzTest.php",
		], $this->rich()->known());
	}

	public function testVouchesForAFileThatStillKeysTheSame(): void {
		self::assertTrue($this->snapshot(["src/A.php" => 4])->vouchesFor("src/A.php", "key-of-src/A.php"));
	}

	public function testDoesNotVouchForAFileThatHasMoved(): void {
		self::assertFalse($this->snapshot(["src/A.php" => 4])->vouchesFor("src/A.php", "a new key"));
	}

	public function testDoesNotVouchForAFileItNeverProved(): void {
		self::assertFalse($this->snapshot()->vouchesFor("src/A.php", "key-of-src/A.php"));
	}

	public function testDoesNotVouchForAFileWhoseKeyHasMoved(): void {
		self::assertFalse($this->snapshot(["src/A.php" => 4])->vouchesFor("src/A.php", "moved"));
	}

	public function testCountsTheMutantsItHasProven(): void {
		self::assertSame(6, $this->snapshot(["src/A.php" => 4, "src/B.php" => 2])->mutants());
	}

	/**
	 * A file examined and found to have nothing to mutate is a claim, not an
	 * absence — which is what lets G1 fail on a file that produced no mutants it
	 * should have.
	 *
	 * @return void
	 */
	public function testAFileWithNothingToMutateIsStillProven(): void {
		$snapshot = $this->snapshot(["src/Interface.php" => 0]);

		self::assertTrue($snapshot->vouchesFor("src/Interface.php", "key-of-src/Interface.php"));
		self::assertSame(0, $snapshot->mutants());
	}

	/**
	 * A run that re-proved three files knows nothing about the other four
	 * hundred, so its empty sections must not erase them.
	 *
	 * @return void
	 */
	public function testAPartialRunDoesNotEraseWhatItNeverLookedAt(): void {
		$merged = $this->snapshot(["src/A.php" => 4, "src/B.php" => 2])
			->with($this->snapshot(["src/B.php" => 3]));

		self::assertSame(["src/A.php", "src/B.php"], array_keys($merged->verdicts));
		self::assertSame(3, $merged->verdicts["src/B.php"]->mutants);
	}

	public function testALaterRunProvingAFileTakesItOutOfUnproven(): void {
		$merged = $this->snapshot([], ["src/A.php"])->with($this->snapshot(["src/A.php" => 4]));

		self::assertSame([], $merged->unproven);
		self::assertTrue($merged->vouchesFor("src/A.php", "key-of-src/A.php"));
	}

	/**
	 * The half without which a store only ever grows and no claim can be taken
	 * back.
	 *
	 * @return void
	 */
	public function testALaterRunFailingToProveAFileTakesTheVerdictAway(): void {
		$merged = $this->snapshot(["src/A.php" => 4])->with($this->snapshot([], ["src/A.php"]));

		self::assertSame([], $merged->verdicts);
		self::assertSame(["src/A.php"], $merged->unproven);
	}

	/**
	 * Measured on a real project: a gate change left 610 verdicts standing of
	 * which one run had earned 221. Verdicts proven under another engine, driver
	 * or mutator set are not old — they are answers to a different question.
	 *
	 * @return void
	 */
	public function testADifferentToolchainReplacesTheStoreOutright(): void {
		$merged = $this->snapshot(["src/A.php" => 4, "src/B.php" => 2], ["src/C.php"])
			->with($this->snapshot(["src/B.php" => 3], [], "a different gate"));

		self::assertSame(["src/B.php"], array_keys($merged->verdicts));
		self::assertSame([], $merged->unproven);
		self::assertSame("a different gate", $merged->toolchain);
	}

	/**
	 * The store is committed, so two machines that proved the same files have to
	 * write the same bytes — and the order verdicts arrive in is the order a
	 * coverage report or a cache happened to list them. Caught by building a
	 * 404-verdict store twice at real scale, where it was not true.
	 *
	 * @return void
	 */
	public function testIsCanonicalWhateverOrderItIsBuiltIn(): void {
		$forwards = $this->snapshot(["src/A.php" => 1, "src/Z.php" => 2], ["src/M.php", "src/B.php"]);
		$backwards = $this->snapshot(["src/Z.php" => 2, "src/A.php" => 1], ["src/B.php", "src/M.php"]);

		self::assertSame(array_keys($forwards->verdicts), array_keys($backwards->verdicts));
		self::assertSame(["src/A.php", "src/Z.php"], array_keys($forwards->verdicts));
		self::assertSame($forwards->unproven, $backwards->unproven);
	}

	public function testTheUnprovenListCarriesNoRepeats(): void {
		self::assertSame(["src/A.php"], $this->snapshot([], ["src/A.php", "src/A.php"])->unproven);
	}

	/**
	 * **The test record replaces rather than merges, and it is the one section
	 * that does.** A run's verdicts are only ever about the files it examined, but
	 * its record of what each test executes is the whole current picture: a warm
	 * run starts from what the store said and writes the re-measured test over it.
	 *
	 * Merging the old map back in would resurrect a test file that has since been
	 * deleted, and a deleted test that keeps appearing among a file's covering
	 * tests gives that file a key nothing can ever reproduce — so it reads as
	 * outstanding on every run from then on.
	 *
	 * @return void
	 */
	public function testTheTestRecordIsTheNewerRunsWhole(): void {
		$merged = $this->snapshot([], [], "chain", ["tests/ATest.php" => new Footprint("sig", ["src/A.php"])])
			->with($this->snapshot([], [], "chain", ["tests/BTest.php" => new Footprint("sig", ["src/B.php"])]));

		self::assertSame(["tests/BTest.php"], array_keys($merged->tests));
	}

	public function testTheMergedVerdictsAreSorted(): void {
		$merged = $this->snapshot(["src/Z.php" => 1])->with($this->snapshot(["src/A.php" => 1]));

		self::assertSame(["src/A.php", "src/Z.php"], array_keys($merged->verdicts));
	}

	public function testTheMergedUnprovenIsSortedAndFreeOfRepeats(): void {
		$merged = $this->snapshot([], ["src/Z.php", "src/A.php"])
			->with($this->snapshot([], ["src/A.php", "src/M.php"]));

		self::assertSame(["src/A.php", "src/M.php", "src/Z.php"], $merged->unproven);
	}

	/**
	 * **The half of the cheap question that keys cannot answer.** A test file's
	 * digest reaches a key only through the files it covers, so a test that covers
	 * nothing has its bytes in no key — and editing it to start covering something
	 * moves nothing. Asking the record directly is what notices, and it has to name
	 * every one of them.
	 *
	 * @return void
	 */
	public function testNamesEveryTestWhoseBytesHaveMoved(): void {
		$snapshot = $this->snapshot([], [], "chain", [
			"tests/ATest.php" => new Footprint("was", ["src/A.php"]),
			"tests/BTest.php" => new Footprint("was", []),
			"tests/CTest.php" => new Footprint("still", []),
		]);

		self::assertSame(["tests/ATest.php", "tests/BTest.php"], $snapshot->unmeasured([
			"tests/ATest.php" => "now",
			"tests/BTest.php" => "now",
			"tests/CTest.php" => "still",
		]));
	}

	/**
	 * A test file the tree no longer has is not unmeasured, it is gone — and
	 * `Runner\Run` drops it before anything is asked about it.
	 *
	 * @return void
	 */
	public function testATestTheTreeNoLongerHasIsNotUnmeasured(): void {
		$snapshot = $this->snapshot([], [], "chain", ["tests/ATest.php" => new Footprint("was", [])]);

		self::assertSame([], $snapshot->unmeasured([]));
	}
}
