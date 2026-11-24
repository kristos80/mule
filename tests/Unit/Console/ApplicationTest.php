<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Console\Application;
use Kristos80\Mule\External\ProcessesInterface;
use Kristos80\Mule\Tests\Support\CoveringProcesses;
use Kristos80\Mule\Tests\Support\FixedRandom;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\MappedClasses;
use Kristos80\Mule\Tests\Support\RecordingOutput;
use Kristos80\Mule\Tests\Support\ThrowingProcesses;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	private CoveringProcesses $processes;

	private RecordingOutput $output;

	private RecordingOutput $errors;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", "{}")
			->given("/project/src/Key.php", "<?php class Key {}")
			->given("/project/tests/KeyTest.php", "<?php class KeyTest {}");
		$this->processes = new CoveringProcesses($this->filesystem, ["Key.php" => ["Acme\\Tests\\KeyTest::testOne"]]);
		$this->output = new RecordingOutput();
		$this->errors = new RecordingOutput();
	}

	public function testMutatesTheWholeProjectForFull(): void {
		self::assertSame(0, $this->ran(["full"]));

		self::assertSame(2, $this->processes->count());
		self::assertNotContains("--filter=src/Key.php", $this->processes->command(1));
	}

	public function testMutatesOnlyWhatTheStoreCannotVouchForForNew(): void {
		self::assertSame(0, $this->ran(["new"]));

		self::assertContains("--filter=src/Key.php", $this->processes->command(1));
	}

	public function testMutatesNothingForStatus(): void {
		self::assertSame(0, $this->ran(["status"]));

		self::assertSame(1, $this->processes->count());
		self::assertSame("mule: 0 of 1 files verified, 1 stale.", $this->output->text());
	}

	public function testPassesOnTheStatusOfAFailedRun(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [], "/project/src", [7]);

		self::assertSame(7, $this->ran(["full"]));
	}

	public function testReportsAnUnknownVerbOnStandardErrorWithoutStartingAnything(): void {
		self::assertSame(1, $this->ran(["diff"]));

		self::assertSame(0, $this->processes->count());
		self::assertSame("mule: Unknown verb diff. usage: mule new|full|status", $this->errors->text());
		self::assertSame("", $this->output->text());
	}

	public function testReportsAFailureFromAnywhereInsideOnStandardError(): void {
		self::assertSame(1, $this->ran(["full"], "/elsewhere"));

		self::assertSame(
			"mule: No composer.json at or above /elsewhere: mule needs a project root.",
			$this->errors->text(),
		);
	}

	/**
	 * A fault in mule is not a mistake in the project being gated, and the two
	 * read differently: anything that is not a Failure reached the top because
	 * mule itself broke.
	 *
	 * @return void
	 */
	public function testSaysWhenAFailureIsMulesOwnFaultRatherThanTheProjects(): void {
		self::assertSame(1, $this->ran(["full"], "/project", new ThrowingProcesses()));

		self::assertSame(
			"mule: LogicException: the coverage command was never built — that is a fault in mule rather than in your project, so please report it.",
			$this->errors->text(),
		);
	}

	/**
	 * @param list<string> $arguments
	 * @param string $directory
	 * @param ProcessesInterface|null $processes Something other than the coverage-writing spy.
	 * @return int
	 */
	private function ran(array $arguments, string $directory = "/project", ?ProcessesInterface $processes = NULL): int {
		$this->filesystem->givenDirectory("/elsewhere");

		$application = new Application(
			$this->filesystem,
			$processes ?? $this->processes,
			new MappedClasses(["Acme\\Tests\\KeyTest" => "/project/tests/KeyTest.php"]),
			new FixedRandom("cafe1234"),
			$this->output,
			$this->errors,
		);

		return $application->run($arguments, $directory);
	}
}
