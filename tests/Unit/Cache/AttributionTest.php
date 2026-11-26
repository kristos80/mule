<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Attribution;
use Kristos80\Mule\Cache\Touched;
use PHPUnit\Framework\TestCase;

final class AttributionTest extends TestCase {

	public function testKnowsWhenEveryTestCoversExactlyOneFile(): void {
		self::assertTrue(Attribution::bindsEveryTestToOneFile([
			"tests/ATest.php" => new Touched("tt", ["src/A.php"]),
			"tests/BTest.php" => new Touched("uu", ["src/B.php"]),
		]));
	}

	public function testKnowsWhenATestRunsMoreThanOneFile(): void {
		self::assertFalse(Attribution::bindsEveryTestToOneFile([
			"tests/ATest.php" => new Touched("tt", ["src/A.php", "src/B.php"]),
			"tests/BTest.php" => new Touched("uu", ["src/B.php"]),
		]));
	}

	/**
	 * Fewer than two tests is not evidence of anything.
	 *
	 * @return void
	 */
	public function testTreatsTooFewTestsAsEvidenceOfNothing(): void {
		self::assertFalse(Attribution::bindsEveryTestToOneFile([
			"tests/ATest.php" => new Touched("tt", ["src/A.php"]),
		]));
		self::assertFalse(Attribution::bindsEveryTestToOneFile([]));
	}
}
