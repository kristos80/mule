<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Runner\Run;
use PHPUnit\Framework\TestCase;

final class RunTest extends TestCase {

	public function testNamesWhereCoverageIsWritten(): void {
		self::assertSame("/tmp/mule.cafe/coverage-xml", (new Run("/tmp/mule.cafe"))->coverageXml());
	}

	public function testNamesWhereTheJunitLogIsWritten(): void {
		self::assertSame("/tmp/mule.cafe/junit.xml", (new Run("/tmp/mule.cafe"))->junit());
	}

	public function testNamesWhereTheEnginesConfigurationIsWritten(): void {
		self::assertSame("/tmp/mule.cafe/infection.json", (new Run("/tmp/mule.cafe"))->engineJson());
	}

	public function testNamesWhereTheEngineLogsItsMutants(): void {
		self::assertSame("/tmp/mule.cafe/mutants.json", (new Run("/tmp/mule.cafe"))->mutantsJson());
	}
}
