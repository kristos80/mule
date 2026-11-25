<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Console\Summary;
use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Coverage\Unreached;
use PHPUnit\Framework\TestCase;

final class SummaryTest extends TestCase {

	public function testSaysHowMuchOfTheProjectTheStoreVouchesFor(): void {
		self::assertSame("mule: 17 of 20 files verified, 3 stale.", Summary::verified(20, 3, 0));
	}

	public function testSaysSoWhenEverythingIsVerified(): void {
		self::assertSame("mule: 20 of 20 files verified, 0 stale.", Summary::verified(20, 0, 0));
	}

	public function testCountsWhatItCannotBankSeparatelyFromWhatIsStale(): void {
		self::assertSame(
			"mule: 16 of 20 files verified, 3 stale, 1 not covered in full.",
			Summary::verified(20, 3, 1),
		);
	}

	public function testSaysHowManyLinesTheTestsReach(): void {
		self::assertSame(
			["mule: 1250 of 1266 lines covered (98.7%)."],
			Summary::coverage(new Reach(1266, 1250)),
		);
	}

	public function testSaysSoWhenTheTestsReachEveryLine(): void {
		self::assertSame(
			["mule: 1266 of 1266 lines covered (100.0%)."],
			Summary::coverage(new Reach(1266, 1266)),
		);
	}

	/**
	 * Rounded down, never up: a project one line short of complete is not
	 * reported at a hundred percent.
	 *
	 * @return void
	 */
	public function testRoundsTheProportionDown(): void {
		self::assertSame(
			["mule: 9999 of 10000 lines covered (99.9%)."],
			Summary::coverage(new Reach(10000, 9999)),
		);
	}

	/**
	 * `0.29 * 1000` is `289.999…` in binary, so a tenth worked out in floating
	 * point and floored reports this project at 28.9 %.
	 *
	 * @return void
	 */
	public function testWorksTheProportionOutWithoutLosingATenthToABinaryFraction(): void {
		self::assertSame(
			["mule: 29 of 100 lines covered (29.0%)."],
			Summary::coverage(new Reach(100, 29)),
		);
	}

	public function testSaysNothingAboutCoverageWhenThereIsNoneRecorded(): void {
		self::assertSame([], Summary::coverage(Reach::none()));
	}

	public function testSaysWhenCoverageIsBelowWhatTheProjectAsksFor(): void {
		self::assertSame(
			"mule: that is below the 80% this project asks for.",
			Summary::belowMinimumCoverage(80),
		);
	}

	public function testSaysWhenTheEngineMovedUnderTheVerdicts(): void {
		self::assertSame(
			["mule: every verdict is stale because the engine moved, from "
				. "infection/infection 0.32.0 to infection/infection 0.32.7."],
			Summary::engineMoved(["infection/infection" => "0.32.0"], ["infection/infection" => "0.32.7"]),
		);
	}

	public function testSaysNothingWhenTheEngineIsTheOneThatProvedThem(): void {
		self::assertSame([], Summary::engineMoved(["i" => "1.0"], ["i" => "1.0"]));
	}

	/**
	 * A store written before mule recorded this, or a partial report that
	 * cannot say: neither is an engine that moved.
	 *
	 * @return void
	 */
	public function testSaysNothingWhereEitherEngineIsUnrecorded(): void {
		self::assertSame([], Summary::engineMoved([], ["i" => "1.0"]));
		self::assertSame([], Summary::engineMoved(["i" => "1.0"], []));
	}

	public function testNamesEveryPackageRatherThanTheFirst(): void {
		self::assertSame(
			["mule: every verdict is stale because the engine moved, from a 1.0, b 1.0 to a 2.0, b 2.0."],
			Summary::engineMoved(["a" => "1.0", "b" => "1.0"], ["a" => "2.0", "b" => "2.0"]),
		);
	}

	public function testSaysNothingAboutGapsWhenThereAreNone(): void {
		self::assertSame([], Summary::unreached([]));
	}

	public function testSaysWhereTheTestsStopInEachFileItCannotBank(): void {
		self::assertSame([
			"mule: not banked until the tests reach them:",
			"  src/A.php — one() lines 10-19, 2 unreached; two() lines 21-25, 1 unreached",
			"  src/B.php — three() lines 4-8, 5 unreached",
		], Summary::unreached([
			"src/B.php" => [new Unreached("three", 4, 8, 5)],
			"src/A.php" => [new Unreached("one", 10, 19, 2), new Unreached("two", 21, 25, 1)],
		]));
	}

	public function testSaysSoWhenTheReportGivesNoRangesToPointAt(): void {
		self::assertSame([
			"mule: not banked until the tests reach them:",
			"  src/A.php — the report gives no method ranges",
		], Summary::unreached(["src/A.php" => []]));
	}

	public function testSaysNothingWhenNothingChangedUnderTheGate(): void {
		self::assertSame([], Summary::moved(0));
	}

	public function testSaysWhatChangedUnderTheGate(): void {
		self::assertSame(
			["mule: 2 file(s) changed while the gate ran and were not recorded."],
			Summary::moved(2),
		);
	}

	public function testSaysNothingAboutAttributionWhenTheReportIsNotBoundByIt(): void {
		self::assertSame([], Summary::attributionBound(FALSE));
	}

	public function testSaysSoWhenTheReportCannotDescribeADependency(): void {
		self::assertSame(
			["mule: every test here covers exactly one file, so no change can mark another file stale — "
				. "see the README on coverage attribution."],
			Summary::attributionBound(TRUE),
		);
	}

	public function testSaysThereIsNothingToMutate(): void {
		self::assertSame(
			"mule: nothing to mutate, every file is verified against its own tests.",
			Summary::nothingToMutate(),
		);
	}

	public function testSaysWhatItBankedAndWhere(): void {
		self::assertSame(
			"mule: 18 file(s) recorded at the full score in .mule/verified.json.",
			Summary::banked(18, ".mule/verified.json"),
		);
	}

	public function testPrefixesAFailureWithTheProgramsName(): void {
		self::assertSame("mule: No composer.json above here.", Summary::failed("No composer.json above here."));
	}

	public function testSaysWhoseFaultAFaultInMuleIs(): void {
		self::assertSame(
			"mule: TypeError: argument #1 must be a string — that is a fault in mule rather than in your project, so please report it.",
			Summary::crashed("TypeError", "argument #1 must be a string"),
		);
	}
}
