<?php declare(strict_types=1);
namespace Fixture\Tests;

use Fixture\Hub;
use Fixture\Leaf;
use Fixture\Trunk;
use PHPUnit\Framework\TestCase;

final class TrunkTest extends TestCase {
	public function testQuadruples(): void {
		self::assertSame(8, new Trunk(new Leaf())->quadrupled(new Hub(2)));
	}

	public function testQuadruplesZero(): void {
		self::assertSame(0, new Trunk(new Leaf())->quadrupled(new Hub(0)));
	}

	/** Pushes this class's junit time up, so its covering set can cross a timeout. */
	public function testTakesItsTime(): void {
		usleep(120_000);
		self::assertSame(4, new Trunk(new Leaf())->quadrupled(new Hub(1)));
	}
}
