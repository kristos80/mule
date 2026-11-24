<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit;

use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Engine\Infection;
use Kristos80\Mule\Gate;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Scratch;
use Kristos80\Mule\Tests\Support\CoveringProcesses;
use Kristos80\Mule\Tests\Support\FixedRandom;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\MappedClasses;
use Kristos80\Mule\Tests\Support\RecordingOutput;
use PHPUnit\Framework\TestCase;

/**
 * The gate end to end, over a project that exists only in memory.
 *
 * Every one of these is the real program: argument to exit status, coverage
 * command to engine command line to the bytes left in the store. Nothing is
 * stubbed except the five things that would otherwise touch the machine.
 */
final class GateTest extends TestCase {

	private const string RUN = "/tmp/mule.cafe1234";

	private const string STORE = "/project/.mule/verified.json";

	private InMemoryFilesystem $filesystem;

	private CoveringProcesses $processes;

	private RecordingOutput $output;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", "{}")
			->given("/project/src/Key.php", "<?php class Key {}")
			->given("/project/src/Gate.php", "<?php class Gate {}")
			->given("/project/tests/KeyTest.php", "<?php class KeyTest {}")
			->given("/project/tests/GateTest.php", "<?php class GateTest {}");
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);
		$this->output = new RecordingOutput();
	}

	public function testRunsCoverageAndThenTheEngineOverTheWholeProject(): void {
		self::assertSame(0, $this->gate()->full());

		self::assertSame(2, $this->processes->count());
		self::assertSame([
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
		], $this->processes->command(0));
		self::assertSame([
			"infection",
			"--coverage=" . self::RUN,
			"--skip-initial-tests",
			"--min-msi=100",
			"--min-covered-msi=100",
		], $this->processes->command(1));
	}

	public function testRunsBothProcessesInTheProjectRoot(): void {
		$this->gate()->full();

		self::assertSame("/project", $this->processes->directory(0));
		self::assertSame("/project", $this->processes->directory(1));
	}

	public function testBanksEveryFileAWholeProjectRunProved(): void {
		$this->gate()->full();

		self::assertSame(["src/Gate.php", "src/Key.php"], $this->stored());
		self::assertSame("mule: 2 file(s) recorded at the full score in .mule/verified.json.", $this->output->text());
	}

	public function testNarrowsToEveryFileWhenTheStoreVouchesForNothing(): void {
		self::assertSame(0, $this->gate()->narrowed());

		self::assertSame("--filter=src/Gate.php,src/Key.php", $this->processes->command(1)[5] ?? "");
		self::assertSame("--ignore-msi-with-no-mutations", $this->processes->command(1)[6] ?? "");
		self::assertStringContainsString("mule: 0 of 2 files verified, 2 stale.", $this->output->text());
	}

	public function testMutatesNothingWhenTheStoreVouchesForEveryFile(): void {
		$this->gate()->full();
		$this->output = new RecordingOutput();
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);

		self::assertSame(0, $this->gate()->narrowed());

		self::assertSame(1, $this->processes->count());
		self::assertSame(
			"mule: 2 of 2 files verified, 0 stale.\nmule: nothing to mutate, every file is verified against its own tests.",
			$this->output->text(),
		);
	}

	public function testNarrowsToTheFileWhoseSourceChanged(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");

		$this->gate()->narrowed();

		self::assertSame("--filter=src/Key.php", $this->processes->command(3)[5] ?? "");
		self::assertStringContainsString("mule: 1 of 2 files verified, 1 stale.", $this->output->text());
		self::assertStringContainsString("mule: 1 file(s) recorded at the full score", $this->output->text());
	}

	public function testNarrowsToEveryFileACoveringTestTouches(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/tests/GateTest.php", "<?php class GateTest { /* one assertion fewer */ }");

		$this->gate()->narrowed();

		self::assertSame("--filter=src/Gate.php", $this->processes->command(3)[5] ?? "");
	}

	public function testKeepsWhatTheStoreAlreadyVouchedForWhenBankingANarrowedRun(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");

		$this->gate()->narrowed();

		self::assertSame(["src/Gate.php", "src/Key.php"], $this->stored());
	}

	public function testGivesUpWhenCoverageFailsAndBanksNothing(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [], "/project/src", [2]);

		self::assertSame(2, $this->gate()->full());

		self::assertSame(1, $this->processes->count());
		self::assertFalse($this->filesystem->exists(self::STORE));
		self::assertSame("", $this->output->text());
	}

	public function testBanksNothingWhenTheEngineFails(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
		], "/project/src", [0, 1]);

		self::assertSame(1, $this->gate()->full());

		self::assertFalse($this->filesystem->exists(self::STORE));
	}

	public function testReportsWhatTheStoreClaimsWithoutMutatingAnything(): void {
		$this->gate()->full();
		$this->output = new RecordingOutput();

		self::assertSame(0, $this->gate()->status());

		self::assertSame("mule: 2 of 2 files verified, 0 stale.", $this->output->text());
		self::assertSame(3, $this->processes->count());
	}

	public function testGivesUpOnStatusWhenCoverageFails(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [], "/project/src", [3]);

		self::assertSame(3, $this->gate()->status());

		self::assertSame("", $this->output->text());
	}

	public function testLeavesNoScratchDirectoryBehind(): void {
		$this->gate()->full();
		$this->gate()->status();

		self::assertFalse($this->filesystem->exists(self::RUN));
	}

	public function testLeavesNoScratchDirectoryBehindWhenCoverageFails(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [], "/project/src", [4]);

		$this->gate()->full();

		self::assertFalse($this->filesystem->exists(self::RUN));
	}

	/**
	 * @return Gate
	 */
	private function gate(): Gate {
		$project = Project::containing("/project", $this->filesystem);
		$random = new FixedRandom("cafe1234");
		$classes = new MappedClasses([
			"Acme\\Tests\\KeyTest" => "/project/tests/KeyTest.php",
			"Acme\\Tests\\GateTest" => "/project/tests/GateTest.php",
		]);

		return new Gate(
			$project,
			Configuration::load($project, $this->filesystem),
			new Keying($project, $this->filesystem, $classes),
			new Store($project, $this->filesystem, $random),
			new Infection("infection"),
			new Scratch($this->filesystem, $random),
			$this->processes,
			$this->output,
		);
	}

	/**
	 * The paths the store vouches for now.
	 *
	 * @return list<string>
	 */
	private function stored(): array {
		$store = new Store(
			Project::containing("/project", $this->filesystem),
			$this->filesystem,
			new FixedRandom(),
		);

		return $store->read()->paths();
	}
}
