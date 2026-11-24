<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Unreached;
use PHPUnit\Framework\TestCase;

final class UnreachedTest extends TestCase {

	public function testDescribesItselfWhereAPersonHasToReadIt(): void {
		self::assertSame("probe() lines 77-83, 3 unreached", (new Unreached("probe", 77, 83, 3))->described());
	}

	public function testRecordsItselfAsTheStoreKeepsIt(): void {
		self::assertSame(
			["name" => "probe", "from" => 77, "to" => 83, "lines" => 3],
			(new Unreached("probe", 77, 83, 3))->toArray(),
		);
	}
}
