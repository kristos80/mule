<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Unproven;
use Kristos80\Mule\Coverage\Unreached;
use PHPUnit\Framework\TestCase;

final class UnprovenTest extends TestCase {

	public function testRecordsItselfAsTheStoreKeepsIt(): void {
		$unproven = new Unproven("bb", [new Unreached("one", 4, 9, 2), new Unreached("two", 11, 14, 1)]);

		self::assertSame([
			"digest" => "bb",
			"unreached" => [
				["name" => "one", "from" => 4, "to" => 9, "lines" => 2],
				["name" => "two", "from" => 11, "to" => 14, "lines" => 1],
			],
		], $unproven->toArray());
	}

	public function testRecordsAFileWithNoRangesToPointAt(): void {
		self::assertSame(["digest" => "bb", "unreached" => []], (new Unproven("bb", []))->toArray());
	}
}
