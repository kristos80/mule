<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Key;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Standing;
use Kristos80\Mule\Cache\Verdict;
use PHPUnit\Framework\TestCase;

final class StandingTest extends TestCase {

	/**
	 * `ATest` runs `A.php` and the shared `Hub.php`; `BTest` runs `B.php` and
	 * `Hub.php` too. So `Hub.php` is a dependency of both verdicts, and a change
	 * to it reaches files that never mention it.
	 *
	 * @var array<string, list<string>>
	 */
	private const array TESTS = [
		"tests/ATest.php" => ["src/A.php", "src/Hub.php"],
		"tests/BTest.php" => ["src/B.php", "src/Hub.php"],
	];

	/**
	 * @return array<string, non-empty-string>
	 */
	private function digests(): array {
		return [
			"src/A.php" => "a",
			"src/B.php" => "b",
			"src/Hub.php" => "h",
			"tests/ATest.php" => "ta",
			"tests/BTest.php" => "tb",
		];
	}

	/**
	 * A store that proves A and B under the digests above.
	 *
	 * @param list<string> $unproven
	 * @return Snapshot
	 */
	private function stored(array $unproven = [], string $toolchain = "chain"): Snapshot {
		/** @var non-empty-string $toolchain */
		$blank = new Snapshot([], [], self::TESTS, $toolchain);
		$digests = $this->digests();
		$verdicts = [];

		foreach(["src/A.php" => 4, "src/B.php" => 2, "src/Hub.php" => 1] as $path => $mutants) {
			$deps = [];

			foreach($blank->dependenciesOf($path) as $dep) {
				$deps[$dep] = $digests[$dep];
			}

			$tests = [];

			foreach($blank->coveredBy($path) as $test) {
				$tests[$test] = $digests[$test];
			}

			$verdicts[$path] = new Verdict(Key::of($path, $digests[$path], $deps, $tests, $toolchain), $mutants);
		}

		return new Snapshot($verdicts, $unproven, self::TESTS, $toolchain);
	}

	public function testAStoreThatDescribesTheTreeHasNothingOutstanding(): void {
		$standing = Standing::of($this->stored(), $this->digests(), "chain");

		self::assertTrue($standing->usable);
		self::assertSame([], $standing->outstanding);
		self::assertSame("the store describes this tree", $standing->because);
	}

	/**
	 * **The relation is symmetric through a shared test, and that is not a
	 * mistake.** Editing `A.php` reaches `Hub.php` as well: `Hub.php` is covered
	 * by `ATest`, so `ATest`'s behaviour — and therefore every `Hub.php` verdict
	 * proven against it — depends on `A.php`.
	 *
	 * It is also why a hub file is expensive in both directions: it has a wide
	 * closure *and* it sits in everyone else's.
	 *
	 * @return void
	 */
	public function testAFileThatMovedReachesWhateverSharesATestWithIt(): void {
		$digests = [...$this->digests(), "src/A.php" => "edited"];

		self::assertSame(
			["src/A.php", "src/Hub.php"],
			Standing::of($this->stored(), $digests, "chain")->outstanding,
		);
	}

	/**
	 * The breadth of the key, seen from the store: `Hub.php` moving reaches
	 * `A.php` and `B.php`, neither of which mentions it. The edge runs through
	 * the tests they share.
	 *
	 * @return void
	 */
	public function testAFileMovingReachesEverythingItsTestsAlsoRun(): void {
		$digests = [...$this->digests(), "src/Hub.php" => "edited"];

		self::assertSame(
			["src/A.php", "src/B.php", "src/Hub.php"],
			Standing::of($this->stored(), $digests, "chain")->outstanding,
		);
	}

	public function testATestMovingReachesEverythingItCovers(): void {
		$digests = [...$this->digests(), "tests/ATest.php" => "edited"];

		self::assertSame(
			["src/A.php", "src/Hub.php"],
			Standing::of($this->stored(), $digests, "chain")->outstanding,
		);
	}

	/**
	 * A file mule cannot hash is not a file that stayed the same.
	 *
	 * @return void
	 */
	public function testAFileThatHasGoneIsOutstanding(): void {
		$digests = $this->digests();
		unset($digests["src/A.php"]);

		self::assertContains("src/A.php", Standing::of($this->stored(), $digests, "chain")->outstanding);
	}

	public function testWhatWasNeverProvenIsOutstanding(): void {
		$standing = Standing::of($this->stored(["src/Later.php"]), $this->digests(), "chain");

		self::assertSame(["src/Later.php"], $standing->outstanding);
	}

	/**
	 * Nothing the store holds was proven under another environment, so there is
	 * nothing to compare and the answer is a coverage run rather than a list.
	 *
	 * @return void
	 */
	public function testAChangedToolchainMakesTheStoreUnusable(): void {
		$standing = Standing::of($this->stored(), $this->digests(), "a different gate");

		self::assertFalse($standing->usable);
		self::assertSame([], $standing->outstanding);
		self::assertStringContainsString("verification environment has changed", $standing->because);
	}

	/**
	 * G9. A test that has appeared may cover anything, so its effect on a key
	 * cannot be worked out from digests — the cheap question simply cannot be
	 * answered.
	 *
	 * @return void
	 */
	public function testAFileTheStoreHasNeverHeardOfMakesItUnusable(): void {
		$digests = [...$this->digests(), "tests/NewTest.php" => "n"];

		$standing = Standing::of($this->stored(), $digests, "chain");

		self::assertFalse($standing->usable);
		self::assertSame(
			"1 file(s) the store has never heard of have appeared, starting with tests/NewTest.php;"
			. " what they cover cannot be worked out from digests",
			$standing->because,
		);
	}

	public function testItSaysHowManyFilesHaveAppeared(): void {
		$digests = [...$this->digests(), "src/New.php" => "n", "src/Newer.php" => "n"];

		self::assertSame(
			"2 file(s) the store has never heard of have appeared, starting with src/New.php;"
			. " what they cover cannot be worked out from digests",
			Standing::of($this->stored(), $digests, "chain")->because,
		);
	}

	/**
	 * Sorted, because the unproven list and the moved verdicts arrive as two
	 * sorted runs rather than one.
	 *
	 * @return void
	 */
	public function testTheOutstandingListIsSorted(): void {
		$digests = [...$this->digests(), "src/A.php" => "edited"];

		self::assertSame(
			["src/A.php", "src/Hub.php", "src/Zzz.php"],
			Standing::of($this->stored(["src/Zzz.php"]), $digests, "chain")->outstanding,
		);
	}
}
