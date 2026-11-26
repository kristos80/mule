<?php declare(strict_types=1);
namespace Fixture\Tests;

use Fixture\Hub;
use PHPUnit\Framework\TestCase;

final class HubTest extends TestCase {
	public function testFitsUnder(): void { self::assertTrue(new Hub(1)->fits(2)); }
	public function testFitsExactly(): void { self::assertTrue(new Hub(2)->fits(2)); }
	public function testDoesNotFit(): void { self::assertFalse(new Hub(3)->fits(2)); }
	public function testKeepsItsSize(): void { self::assertSame(3, new Hub(3)->size); }
	public function testClampsWhatIsTooBig(): void { self::assertSame(2, new Hub(5)->clamped(2)); }
	public function testLeavesWhatFitsAlone(): void { self::assertSame(1, new Hub(1)->clamped(2)); }
}
