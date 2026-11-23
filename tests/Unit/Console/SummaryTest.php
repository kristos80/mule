<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Console\Summary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Summary::class)]
final class SummaryTest extends TestCase {

	public function testSaysHowMuchOfTheProjectTheStoreVouchesFor(): void {
		self::assertSame("mule: 17 of 20 files verified, 3 stale.", Summary::verified(20, 3));
	}

	public function testSaysSoWhenEverythingIsVerified(): void {
		self::assertSame("mule: 20 of 20 files verified, 0 stale.", Summary::verified(20, 0));
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
}
