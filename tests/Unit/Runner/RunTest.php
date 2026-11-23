<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Runner\Run;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Run::class)]
final class RunTest extends TestCase {

	public function testNamesWhereCoverageIsWritten(): void {
		self::assertSame("/tmp/mule.cafe/coverage-xml", (new Run("/tmp/mule.cafe"))->coverageXml());
	}

	public function testNamesWhereTheJunitLogIsWritten(): void {
		self::assertSame("/tmp/mule.cafe/junit.xml", (new Run("/tmp/mule.cafe"))->junit());
	}
}
