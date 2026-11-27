<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Console\Prognosis;
use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Doctor\Diagnosis;
use Kristos80\Mule\Doctor\Spread;
use Kristos80\Mule\Doctor\Tier;
use PHPUnit\Framework\TestCase;
use function implode;

final class PrognosisTest extends TestCase {

	/**
	 * @param array<string, Reach> $reach
	 * @param list<string> $attributed
	 * @return string
	 */
	private function text(array $reach = [], array $attributed = []): string {
		$tests = [
			"tests/WideTest.php" => new Footprint("sig", ["src/A.php", "src/B.php", "src/Hub.php"]),
			"tests/NarrowTest.php" => new Footprint("sig", ["src/C.php"]),
		];
		$gated = ["src/A.php", "src/B.php", "src/C.php", "src/Hub.php"];
		$spread = Spread::of($tests, $gated);

		return implode("\n", new Prognosis()->lines(
			new Diagnosis($spread, Tier::of($spread), $tests, ["src/Gen.php"], $attributed, $reach),
		));
	}

	/**
	 * **The figures lead and the letter follows.** Every threshold behind the tier
	 * is a judgement rather than a finding, so a project at 26 % must not read as
	 * categorically different from one at 24 %.
	 *
	 * @return void
	 */
	public function testItLeadsWithTheShapeOfTheProject(): void {
		self::assertStringStartsWith(
			"4 gated file(s), 1 excluded, 2 test file(s).\n\nTier C: the median edit re-proves"
			. " 3 of 4 file(s) — 75 %",
			$this->text(),
		);
	}

	public function testItNamesTheFilesWhoseEditsReachFurthest(): void {
		self::assertStringContainsString(
			"\n\nThe files whose edits reach furthest:"
			. "\n  src/A.php — 3 file(s), 75 %"
			. "\n  src/B.php — 3 file(s), 75 %"
			. "\n  src/Hub.php — 3 file(s), 75 %"
			. "\n  src/C.php — 1 file(s), 25 %\n",
			$this->text(),
		);
	}

	/**
	 * **Five of each is enough to act on.** Longer is a dump rather than a
	 * finding, and a reader who wants the rest can read the store.
	 *
	 * @return void
	 */
	public function testEachListStopsAtFive(): void {
		$reach = [];

		foreach(["A", "B", "C", "D", "E", "F", "G"] as $name) {
			$reach["src/{$name}.php"] = new Reach(10, 0, FALSE);
		}

		self::assertStringContainsString(
			"Gated files the suite does not reach:"
			. "\n  src/A.php — 0 of 10 line(s)"
			. "\n  src/B.php — 0 of 10 line(s)"
			. "\n  src/C.php — 0 of 10 line(s)"
			. "\n  src/D.php — 0 of 10 line(s)"
			. "\n  src/E.php — 0 of 10 line(s)\n\n",
			$this->text($reach),
		);
	}

	/**
	 * Naming the files is a symptom; naming the tests is the cause.
	 *
	 * @return void
	 */
	public function testItNamesTheWidestTestFootprints(): void {
		self::assertStringContainsString(
			"The widest test footprints:\n  tests/WideTest.php — runs 3 file(s)"
			. "\n  tests/NarrowTest.php — runs 1 file(s)",
			$this->text(),
		);
	}

	public function testItNamesWhatTheSuiteDoesNotReach(): void {
		self::assertStringContainsString(
			"Gated files the suite does not reach:\n  src/B.php — 9 of 20 line(s)",
			$this->text(["src/A.php" => new Reach(10, 10, TRUE), "src/B.php" => new Reach(20, 9, FALSE)]),
		);
	}

	public function testAProjectTheSuiteReachesEntirelySaysNothingAboutIt(): void {
		self::assertStringNotContainsString(
			"does not reach",
			$this->text(["src/A.php" => new Reach(10, 10, TRUE)]),
		);
	}

	public function testItNamesAnExcludedFileTheTestsRunAnyway(): void {
		self::assertStringContainsString(
			"Excluded, but your tests run them:\n  src/Gen.php",
			$this->text(attributed: ["src/Gen.php"]),
		);
	}

	public function testNothingExcludedIsRunSaysNothingAboutIt(): void {
		self::assertStringNotContainsString("but your tests run them", $this->text());
	}

	/**
	 * **It ends with advice, including "you do not need this tool".** Two earlier
	 * attempts produced a correct cache that saved nothing on a project whose
	 * figures said so before a line was written.
	 *
	 * @return void
	 */
	public function testItEndsWithTheAdvice(): void {
		self::assertStringEndsWith("buy no time.", $this->text());
		self::assertStringContainsString("\nYou do not need this tool.", $this->text());
	}
}
