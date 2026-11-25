<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Engine\Infection;
use Kristos80\Mule\Runner\Run;
use PHPUnit\Framework\TestCase;

final class InfectionTest extends TestCase {

	public function testMutatesTheWholeProjectAgainstTheCoverageTheRunGenerated(): void {
		self::assertSame([
			"./vendor/bin/infection",
			"run",
			"--configuration=/tmp/mule.cafe/infection.json",
			"--coverage=/tmp/mule.cafe",
			"--skip-initial-tests",
			"--no-interaction",
		], $this->command(NULL));
	}

	/**
	 * The full score is what the gate means rather than what this run is doing,
	 * so it is stated in the configuration mule writes and not out here.
	 *
	 * @return void
	 */
	public function testSaysNothingAboutTheScoreOnTheCommandLine(): void {
		self::assertNotContains("--min-msi=100", $this->command(NULL));
		self::assertNotContains("--min-covered-msi=100", $this->command(NULL));
	}

	public function testNarrowsToTheFilesItIsGiven(): void {
		self::assertSame([
			"--filter=src/A.php,src/B.php",
			"--ignore-msi-with-no-mutations",
		], array_slice($this->command(["src/A.php", "src/B.php"]), -2));
	}

	public function testAsksForAsManyThreadsAsTheProjectWanted(): void {
		self::assertSame(["--threads=max"], array_slice($this->command(NULL, threads: "max"), -1));
	}

	public function testSaysNothingAboutThreadsWhenTheProjectDidNot(): void {
		self::assertNotContains("--threads=", $this->command(NULL));
	}

	public function testAsksForUncoveredCodeToBeMutatedWhereTheProjectWantsIt(): void {
		self::assertSame(["--with-uncovered"], array_slice($this->command(NULL, uncovered: TRUE), -1));
	}

	public function testLeavesUncoveredCodeAloneByDefault(): void {
		self::assertNotContains("--with-uncovered", $this->command(NULL));
	}

	public function testKeepsTheProjectsChoicesBeforeTheNarrowing(): void {
		$command = $this->command(["src/A.php"], threads: "4", uncovered: TRUE);

		self::assertSame([
			"--threads=4",
			"--with-uncovered",
			"--filter=src/A.php",
			"--ignore-msi-with-no-mutations",
		], array_slice($command, -4));
	}

	/**
	 * @param list<string>|null $files
	 * @param string|null $threads
	 * @param bool $uncovered
	 * @return list<string>
	 */
	private function command(?array $files, ?string $threads = NULL, bool $uncovered = FALSE): array {
		$configuration = new Configuration(
			[PHP_BINARY, "./vendor/bin/phpunit"],
			["src"],
			[],
			NULL,
			FALSE,
			["@default"],
			$uncovered,
			NULL,
			NULL,
			$threads,
		);

		return (new Infection($configuration))->command(new Run("/tmp/mule.cafe"), $files);
	}
}
