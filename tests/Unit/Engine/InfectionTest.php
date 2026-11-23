<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

use Kristos80\Mule\Engine\Infection;
use Kristos80\Mule\Runner\Run;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Infection::class)]
final class InfectionTest extends TestCase {

	public function testMutatesTheWholeProjectAgainstTheCoverageTheRunGenerated(): void {
		$command = (new Infection("infection"))->command(new Run("/tmp/mule.cafe"), NULL);

		self::assertSame([
			"infection",
			"--coverage=/tmp/mule.cafe",
			"--skip-initial-tests",
			"--min-msi=100",
			"--min-covered-msi=100",
		], $command);
	}

	public function testPassesTheProjectsOwnOptionsAsGiven(): void {
		$command = (new Infection("infection", ["--threads=max", "--no-progress"]))->command(new Run("/tmp/mule.cafe"), NULL);

		self::assertSame(["--threads=max", "--no-progress"], array_slice($command, -2));
	}

	public function testNamesTheBinaryItWasGiven(): void {
		$command = (new Infection("./vendor/bin/infection"))->command(new Run("/tmp/mule.cafe"), NULL);

		self::assertSame("./vendor/bin/infection", $command[0]);
	}

	public function testNarrowsToTheFilesItIsGiven(): void {
		$command = (new Infection("infection"))->command(new Run("/tmp/mule.cafe"), ["src/A.php", "src/B.php"]);

		self::assertSame([
			"infection",
			"--coverage=/tmp/mule.cafe",
			"--skip-initial-tests",
			"--min-msi=100",
			"--min-covered-msi=100",
			"--filter=src/A.php,src/B.php",
			"--ignore-msi-with-no-mutations",
		], $command);
	}

	public function testKeepsTheProjectsOptionsBeforeTheNarrowing(): void {
		$command = (new Infection("infection", ["--threads=max"]))->command(new Run("/tmp/mule.cafe"), ["src/A.php"]);

		self::assertSame([
			"infection",
			"--coverage=/tmp/mule.cafe",
			"--skip-initial-tests",
			"--min-msi=100",
			"--min-covered-msi=100",
			"--threads=max",
			"--filter=src/A.php",
			"--ignore-msi-with-no-mutations",
		], $command);
	}
}
