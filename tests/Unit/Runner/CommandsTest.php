<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Runner\Commands;
use PHPUnit\Framework\TestCase;
use function implode;

final class CommandsTest extends TestCase {

	/**
	 * @param string $ini
	 * @param array<string, mixed> $settings
	 * @return Commands
	 */
	private function commands(string $ini = "/etc/php.ini", array $settings = []): Commands {
		return new Commands(Configuration::of($settings), "/usr/bin/php", $ini);
	}

	public function testCapturingTheWholeSuite(): void {
		self::assertSame(
			[
				"/usr/bin/php",
				"-c",
				"/etc/php.ini",
				"vendor/bin/phpunit",
				"--no-output",
				"--coverage-php=/p/.mule/cache/capture.cov",
				"--log-junit=/p/.mule/cache/junit.xml",
			],
			$this->commands()->capture("/p/.mule/cache/capture.cov", "/p/.mule/cache/junit.xml"),
		);
	}

	/**
	 * One test file is the unit the cache is kept in, and on a warm run it is the
	 * only thing re-measured.
	 *
	 * @return void
	 */
	public function testCapturingOneTestFile(): void {
		self::assertSame(
			"tests/Unit/AaaTest.php",
			$this->commands()->capture("/c.cov", "/j.xml", "tests/Unit/AaaTest.php")[7],
		);
	}

	/**
	 * **The parent's ini is forwarded, because a child does not inherit a driver
	 * that arrived on the command line.** A child started without it writes a JUnit
	 * log, no coverage at all, and exits 1 with nothing on stdout because
	 * `--no-output` swallowed the reason.
	 *
	 * @return void
	 */
	public function testAParentWithNoIniForwardsNothing(): void {
		self::assertSame(
			["/usr/bin/php", "vendor/bin/phpunit"],
			array_slice($this->commands(ini: "")->capture("/c.cov", "/j.xml"), 0, 2),
		);
	}

	/**
	 * **Mutation gets no ini, and that is the one asymmetry with capturing.** It
	 * reads a report that already exists, and handing it a driver makes Infection
	 * restart itself through `composer/xdebug-handler`; the restarted process then
	 * cannot locate `vendor/bin/phpunit` and the run dies before a single mutant.
	 *
	 * @return void
	 */
	public function testTheEngineIsNotHandedTheCoverageDriver(): void {
		$command = $this->commands()->mutate("/p/mule.infection.json", "/p/.mule/cache/coverage", []);

		self::assertNotContains("-c", $command);
		self::assertNotContains("/etc/php.ini", $command);
	}

	/**
	 * **Clearing `auto_prepend_file` restores a guarantee rather than bypassing a
	 * check.** Infection 0.35 refuses to run when one is set, because a preloaded
	 * file executes before its mutant interceptor can be installed — so it cannot
	 * promise the tests ran mutated code. Measured: Herd sets one, and every run
	 * died before the first mutant.
	 *
	 * @return void
	 */
	public function testTheEngineIsStartedWithNoPreloadedFile(): void {
		self::assertSame(
			["/usr/bin/php", "-d", "auto_prepend_file=", "vendor/bin/infection"],
			array_slice($this->commands()->mutate("/c.json", "/cov", []), 0, 4),
		);
	}

	/**
	 * Every flag here is load-bearing and `docs/keying.md` says why for each:
	 * `--skip-initial-tests` because the report is handed over rather than
	 * generated (M1), `--with-timeouts` because a timed-out mutant otherwise scores
	 * as a kill (M14), both MSI floors because a zero-mutant run exits 0 without
	 * them (G5).
	 *
	 * @return void
	 */
	public function testEveryLoadBearingFlagIsThere(): void {
		$command = $this->commands()->mutate("/p/mule.infection.json", "/p/.mule/cache/coverage", []);

		self::assertContains("--configuration=/p/mule.infection.json", $command);
		self::assertContains("--coverage=/p/.mule/cache/coverage", $command);
		self::assertContains("--skip-initial-tests", $command);
		self::assertContains("--with-timeouts", $command);
		self::assertContains("--min-msi=100", $command);
		self::assertContains("--min-covered-msi=100", $command);
		self::assertContains("--threads=max", $command);
		self::assertContains("--no-progress", $command);
		self::assertContains("-n", $command);
	}

	/**
	 * **G5.** Measured: a zero-mutant run exits 1 with `--min-msi=100` and exits
	 * **0** once this flag is added. It must never appear in a command mule builds.
	 *
	 * @return void
	 */
	public function testTheFlagThatWouldDefeatTheGateIsNeverThere(): void {
		self::assertStringNotContainsString(
			"ignore-msi-with-no-mutations",
			implode(" ", $this->commands()->mutate("/c.json", "/cov", ["src/A.php"])),
		);
	}

	public function testTheThreadCountIsThePersonsChoice(): void {
		self::assertContains(
			"--threads=4",
			$this->commands(settings: ["threads" => 4])->mutate("/c.json", "/cov", []),
		);
	}

	public function testOnlyWhatIsOwedIsMutated(): void {
		self::assertSame(
			[
				"/usr/bin/php",
				"-d",
				"auto_prepend_file=",
				"vendor/bin/infection",
				"--configuration=/c.json",
				"--coverage=/cov",
				"--skip-initial-tests",
				"--with-timeouts",
				"--threads=max",
				"--min-msi=100",
				"--min-covered-msi=100",
				"--no-progress",
				"-n",
				"--filter=src/A.php,src/B.php",
			],
			$this->commands()->mutate("/c.json", "/cov", ["src/A.php", "src/B.php"]),
		);
	}

	/**
	 * **An empty list is no filter at all, rather than an empty one.** `--filter=`
	 * with nothing after it is a filter matching nothing, which G1 would read as
	 * the engine having filed no mutants against every file at once.
	 *
	 * @return void
	 */
	public function testACompleteRunPassesNoFilter(): void {
		self::assertStringNotContainsString(
			"--filter",
			implode(" ", $this->commands()->mutate("/c.json", "/cov", [])),
		);
	}
}
