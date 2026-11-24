<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Reading;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Coverage\Unreached;
use PHPUnit\Framework\TestCase;

final class ReadingTest extends TestCase {

	public function testCountsEveryFileTheReportKnewAboutProvenOrNot(): void {
		self::assertSame(3, self::reading(["src/A.php", "src/B.php"], ["src/C.php"])->count());
	}

	public function testCountsAReportWhereNothingIsProven(): void {
		self::assertSame(1, self::reading([], ["src/C.php"])->count());
	}

	public function testNamesWhatCannotBeBankedSortedWhateverOrderItArrivedIn(): void {
		$reading = self::reading([], ["src/C.php", "src/A.php"]);

		self::assertSame(["src/A.php", "src/C.php"], $reading->unproven());
	}

	public function testNamesNothingUnprovenWhenTheTestsReachEveryFile(): void {
		self::assertSame([], self::reading(["src/A.php"], [])->unproven());
	}

	/**
	 * @param list<string> $proven
	 * @param list<string> $gaps
	 * @return Reading
	 */
	private static function reading(array $proven, array $gaps): Reading {
		$verified = [];
		$unreached = [];

		foreach($proven as $path) {
			$verified[$path] = "source-of-{$path}";
		}

		foreach($gaps as $path) {
			$unreached[$path] = [new Unreached("one", 1, 9, 2)];
		}

		return new Reading(Snapshot::of($verified, []), $unreached);
	}
}
