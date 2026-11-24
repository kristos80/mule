<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Standing;
use Kristos80\Mule\Coverage\Unreached;
use PHPUnit\Framework\TestCase;

final class StandingTest extends TestCase {

	public function testAStoreThatCannotAnswerIsNeitherCurrentNorSettled(): void {
		$standing = Standing::unusable();

		self::assertFalse($standing->usable);
		self::assertFalse($standing->isCurrent());
		self::assertFalse($standing->isSettled());
		self::assertSame([], $standing->stale);
		self::assertSame([], $standing->gaps);
		self::assertSame(0, $standing->total);
	}

	public function testAStoreWithNothingStaleAndNoGapsIsSettled(): void {
		$standing = Standing::of([], [], 12);

		self::assertTrue($standing->isCurrent());
		self::assertTrue($standing->isSettled());
		self::assertSame(12, $standing->total);
	}

	public function testAStoreWithSomethingStaleIsNeither(): void {
		$standing = Standing::of(["src/A.php"], [], 12);

		self::assertFalse($standing->isCurrent());
		self::assertFalse($standing->isSettled());
	}

	/**
	 * The distinction the two answers exist for: nothing has moved, so the
	 * store can be read out — but a file nothing reaches all of is still work.
	 *
	 * @return void
	 */
	public function testAStoreWithGapsIsCurrentButNotSettled(): void {
		$standing = Standing::of([], ["src/B.php" => [new Unreached("one", 1, 4, 2)]], 12);

		self::assertTrue($standing->isCurrent());
		self::assertFalse($standing->isSettled());
	}
}
