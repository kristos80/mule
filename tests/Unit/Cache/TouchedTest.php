<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Touched;
use PHPUnit\Framework\TestCase;

final class TouchedTest extends TestCase {

	public function testRunsOneOfTheFilesNamed(): void {
		$test = new Touched("tt", ["src/A.php", "src/B.php"]);

		self::assertTrue($test->runsAnyOf(["src/B.php" => 0]));
	}

	public function testRunsNoneOfTheFilesNamed(): void {
		$test = new Touched("tt", ["src/A.php", "src/B.php"]);

		self::assertFalse($test->runsAnyOf(["src/C.php" => 0]));
	}

	public function testRunsNothingWhenNothingIsNamed(): void {
		self::assertFalse((new Touched("tt", ["src/A.php"]))->runsAnyOf([]));
	}

	public function testRunsNothingWhenItTouchesNothing(): void {
		self::assertFalse((new Touched("tt", []))->runsAnyOf(["src/A.php" => 0]));
	}
}
