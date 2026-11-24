<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Verdict;
use PHPUnit\Framework\TestCase;

final class VerdictTest extends TestCase {

	public function testMatchesAVerdictOverTheSameSourceAndTheSameTests(): void {
		self::assertTrue((new Verdict("aa", "bb"))->matches(new Verdict("aa", "bb")));
	}

	public function testDoesNotMatchAVerdictOverADifferentSource(): void {
		self::assertFalse((new Verdict("aa", "bb"))->matches(new Verdict("cc", "bb")));
	}

	public function testDoesNotMatchAVerdictOverDifferentCoveringTests(): void {
		self::assertFalse((new Verdict("aa", "bb"))->matches(new Verdict("aa", "cc")));
	}
}
