<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Doctor;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Doctor\Spread;
use PHPUnit\Framework\TestCase;

final class SpreadTest extends TestCase {

	/**
	 * `ATest` runs `A.php` and the shared `Hub.php`; `BTest` runs `B.php` and
	 * `Hub.php` too; `CTest` runs only `C.php`. So an edit to `A.php` reaches
	 * `Hub.php` through the test they share, and `Hub.php` reaches everything but
	 * `C.php`.
	 *
	 * @param array<string, list<string>> $touches
	 * @return array<string, Footprint>
	 */
	private function tests(array $touches = [
		"tests/ATest.php" => ["src/A.php", "src/Hub.php"],
		"tests/BTest.php" => ["src/B.php", "src/Hub.php"],
		"tests/CTest.php" => ["src/C.php"],
	]): array {
		$tests = [];

		foreach($touches as $test => $ran) {
			$tests[$test] = new Footprint("sig", $ran);
		}

		return $tests;
	}

	/**
	 * @param list<string> $gated
	 * @return Spread
	 */
	private function spread(array $gated = ["src/A.php", "src/B.php", "src/C.php", "src/Hub.php"]): Spread {
		return Spread::of($this->tests(), $gated);
	}

	/**
	 * **The edge runs through the shared test, not through the source.** `A.php`
	 * never mentions `Hub.php`, but a test runs both — so editing `A.php` changes
	 * the behaviour of a test every `Hub.php` verdict was proven against.
	 *
	 * @return void
	 */
	public function testAnEditReachesWhateverSharesATestWithIt(): void {
		self::assertSame(2, $this->spread()->invalidates["src/A.php"]);
	}

	public function testAHubReachesEverythingThatSharesATestWithIt(): void {
		self::assertSame(3, $this->spread()->invalidates["src/Hub.php"]);
	}

	public function testAFileNothingElseSharesATestWithReachesOnlyItself(): void {
		self::assertSame(1, $this->spread()->invalidates["src/C.php"]);
	}

	/**
	 * A file no test runs reaches nothing — and it is still counted, because it is
	 * gated and mule will report it.
	 *
	 * @return void
	 */
	public function testAFileNoTestRunsReachesNothing(): void {
		$spread = $this->spread(["src/A.php", "src/Lonely.php"]);

		self::assertSame(0, $spread->invalidates["src/Lonely.php"]);
	}

	/**
	 * Only gated files are counted on either side: a closure that included
	 * excluded files would report a reach nobody has to re-prove.
	 *
	 * @return void
	 */
	public function testOnlyGatedFilesAreCounted(): void {
		$spread = $this->spread(["src/A.php", "src/Hub.php"]);

		self::assertSame(["src/A.php", "src/Hub.php"], array_keys($spread->invalidates));
		self::assertSame(2, $spread->invalidates["src/Hub.php"]);
		self::assertSame(2, $spread->files);
	}

	/**
	 * **The lower of the two middles on an even count**, because a figure somebody
	 * decides with should not be a number no file actually has.
	 *
	 * @return void
	 */
	public function testTheMedianIsAFigureSomeFileActuallyHas(): void {
		self::assertSame(2, $this->spread()->median());
	}

	/**
	 * Three distinct reaches, so a median read off the wrong end or off an
	 * unsorted list gives a different answer.
	 *
	 * @return void
	 */
	public function testTheMedianIsTheMiddleOfTheSortedDistribution(): void {
		$spread = Spread::of($this->tests([
			"tests/ATest.php" => ["src/A.php"],
			"tests/BTest.php" => ["src/B.php", "src/C.php"],
			"tests/CTest.php" => ["src/C.php", "src/D.php", "src/E.php"],
		]), ["src/A.php", "src/B.php", "src/C.php", "src/D.php", "src/E.php"]);

		// A reaches 1, B reaches 2, C reaches 4, D and E reach 3 each — so sorted
		// the distribution is 1, 2, 3, 3, 4 and its middle is 3.
		self::assertSame(1, $spread->invalidates["src/A.php"]);
		self::assertSame(2, $spread->invalidates["src/B.php"]);
		self::assertSame(4, $spread->invalidates["src/C.php"]);
		self::assertSame(3, $spread->invalidates["src/D.php"]);
		self::assertSame(3, $spread->median());
	}

	/**
	 * **An even count, where the two middles differ.** The distribution is held
	 * descending so the widest can be named, and reading the middle off it without
	 * sorting ascending gives the *upper* of the two — a figure half the project
	 * is below rather than the one it is at.
	 *
	 * @return void
	 */
	public function testTheMedianOfAnEvenDistribution(): void {
		$spread = Spread::of($this->tests([
			"tests/ATest.php" => ["src/A.php"],
			"tests/BTest.php" => ["src/B.php", "src/C.php"],
			"tests/CTest.php" => ["src/C.php", "src/D.php", "src/E.php"],
			"tests/DTest.php" => ["src/E.php", "src/F.php"],
		]), ["src/A.php", "src/B.php", "src/C.php", "src/D.php", "src/E.php", "src/F.php"]);

		// 1, 2, 2, 3, 4, 4 — so the lower middle is 2 and the upper is 3.
		self::assertSame([4, 4, 3, 2, 2, 1], array_values($spread->invalidates));
		self::assertSame(2, $spread->median());
	}

	public function testTheMedianOfNothingIsNothing(): void {
		self::assertSame(0, $this->spread([])->median());
	}

	public function testTheWidestComeFirst(): void {
		self::assertSame(["src/Hub.php" => 3], $this->spread()->widest(1));
	}

	public function testTheShareOfTheProject(): void {
		self::assertSame(75, $this->spread()->share(3));
	}

	public function testTheShareOfNoProjectAtAll(): void {
		self::assertSame(0, $this->spread([])->share(3));
	}

	/**
	 * Not rounded in the project's favour: two of three files is 66 %, and a figure
	 * that rounded up would make a dense project read as a sparse one.
	 *
	 * @return void
	 */
	public function testTheShareIsNotRoundedUp(): void {
		self::assertSame(66, $this->spread(["src/A.php", "src/B.php", "src/Hub.php"])->share(2));
	}
}
