<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Console\Summary;
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
