<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Runner\Measurement;
use Kristos80\Mule\Runner\Run;
use PHPUnit\Framework\TestCase;

final class MeasurementTest extends TestCase {

	public function testWritesBothReportsIntoTheRunsOwnDirectory(): void {
		self::assertSame([
			"/usr/bin/php",
			"./vendor/bin/phpunit",
			"--coverage-xml=/tmp/mule.cafe/coverage-xml",
			"--log-junit=/tmp/mule.cafe/junit.xml",
		], $this->command(NULL));
	}

	/**
	 * Whatever enabled a coverage driver for mule has to be in force for the
	 * runner too, so the interpreter mule is running under is named rather than
	 * left to a shebang.
	 *
	 * @return void
	 */
	public function testRunsUnderTheInterpreterItWasGiven(): void {
		$command = $this->command(NULL, runner: ["/herd/php84", "-c", "/herd/debug.ini", "./vendor/bin/pest"]);

		self::assertSame(["/herd/php84", "-c", "/herd/debug.ini", "./vendor/bin/pest"], array_slice($command, 0, 4));
	}

	public function testWorksCoverageOutFromTheSuiteItWasNamed(): void {
		self::assertSame(["--testsuite=unit"], array_slice($this->command(NULL, tests: "unit"), -1));
	}

	public function testRunsEveryTestWhenNoSuiteWasNamed(): void {
		self::assertNotContains("--testsuite=", $this->command(NULL));
	}

	public function testRunsOnlyTheTestFilesItWasGiven(): void {
		self::assertSame(
			["tests/ATest.php", "tests/BTest.php"],
			array_slice($this->command(["tests/ATest.php", "tests/BTest.php"]), -2),
		);
	}

	/**
	 * The files are the stricter instruction of the two — they are the tests of
	 * whatever is outstanding, whichever suite they sit in — and a runner given
	 * both has no sensible reading of them.
	 *
	 * @return void
	 */
	public function testDropsTheNamedSuiteOnceParticularFilesAreNamed(): void {
		$command = $this->command(["tests/ATest.php"], tests: "unit");

		self::assertNotContains("--testsuite=unit", $command);
		self::assertSame(["tests/ATest.php"], array_slice($command, -1));
	}

	/**
	 * @param list<string>|null $files
	 * @param list<string>|null $runner
	 * @param string|null $tests
	 * @return list<string>
	 */
	private function command(?array $files, ?array $runner = NULL, ?string $tests = NULL): array {
		$configuration = new Configuration(
			$runner ?? ["/usr/bin/php", "./vendor/bin/phpunit"],
			["src"],
			[],
			$tests,
			FALSE,
			["@default"],
			FALSE,
			NULL,
			NULL,
			NULL,
		);

		return (new Measurement($configuration))->command(new Run("/tmp/mule.cafe"), $files);
	}
}
