<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit;

use Kristos80\Mule\Cache\Declared;
use Kristos80\Mule\Cache\Freshness;
use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Universe;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Engine\Declaration;
use Kristos80\Mule\Engine\Infection;
use Kristos80\Mule\Gate;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Measurement;
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

	private const string COMPOSER = '{"autoload": {"psr-4": {"Acme\\\\": "src"}}}';

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
			->given("/project/composer.json", self::COMPOSER)
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
			PHP_BINARY,
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
		], $this->processes->command(0));
		self::assertSame([
			"./vendor/bin/infection",
			"run",
			"--configuration=" . self::RUN . "/infection.json",
			"--coverage=" . self::RUN,
			"--skip-initial-tests",
			"--no-interaction",
			"--mutators=@default,@identical",
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
		self::assertSame(
			"mule: 0 of 2 files verified, 2 stale.\n"
			. "mule: 2 of 2 lines covered (100.0%).\n"
			. "mule: every test here covers exactly one file, so no change can mark another file stale — see the README on coverage attribution.\n"
			. "mule: 2 file(s) recorded at the full score in .mule/verified.json.",
			$this->output->text(),
		);
	}

	public function testNarrowsToEveryFileWhenTheStoreVouchesForNothing(): void {
		self::assertSame(0, $this->gate()->narrowed());

		self::assertSame("--filter=src/Gate.php,src/Key.php", $this->filterOf(1));
		self::assertContains("--ignore-msi-with-no-mutations", $this->processes->command(1));
		self::assertStringContainsString("mule: 0 of 2 files verified, 2 stale.", $this->output->text());
	}

	public function testStartsNothingAtAllWhenTheStoreVouchesForEveryFile(): void {
		$this->gate()->full();
		$this->output = new RecordingOutput();
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);

		self::assertSame(0, $this->gate()->narrowed());

		// Not one process: no coverage run, no engine. The store answered.
		self::assertSame(0, $this->processes->count());
		self::assertSame(
			"mule: 2 of 2 files verified, 0 stale.\n"
			. "mule: 2 of 2 lines covered (100.0%).\n"
			. "mule: every test here covers exactly one file, so no change can mark another file stale — see the README on coverage attribution.\n"
			. "mule: nothing to mutate, every file is verified against its own tests.",
			$this->output->text(),
		);
	}

	/**
	 * Changing what the gate *means* unproves everything, however still the
	 * source files are.
	 *
	 * This is the case that was measured wrong before it was fixed: adding a
	 * mutator family to `mule.json` left mule reporting "nothing to
	 * mutate" about a gate it had just widened, because the store was
	 * re-examined for staleness and no source digest had moved. The engine must
	 * be handed the whole project.
	 *
	 * @return void
	 */
	public function testMutatesEverythingAgainWhenTheEnginesConfigurationChanged(): void {
		$this->filesystem->given("/project/mule.json", '{"mutators": {"default": true}}');
		$this->gate()->full();
		$this->filesystem->given("/project/mule.json", '{"mutators": {"default": true, "equal": true}}');
		$this->output = new RecordingOutput();
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);

		self::assertSame(0, $this->gate()->narrowed());

		self::assertSame(2, $this->processes->count(), "the engine should have run.");
		self::assertSame("--filter=src/Gate.php,src/Key.php", $this->filterOf(1));
		self::assertStringContainsString("mule: 0 of 2 files verified, 2 stale.", $this->output->text());
	}

	/**
	 * The same, for the one verb that mutates nothing: a redefined gate means
	 * the store is not entitled to call anything verified.
	 *
	 * @return void
	 */
	public function testReportsEverythingStaleWhenTheEnginesConfigurationChanged(): void {
		$this->filesystem->given("/project/mule.json", '{"mutators": {"default": true}}');
		$this->gate()->full();
		$this->filesystem->given("/project/mule.json", '{"mutators": {"default": true, "equal": true}}');
		$this->output = new RecordingOutput();

		self::assertSame(0, $this->gate()->status());

		self::assertStringContainsString("mule: 0 of 2 files verified, 2 stale.", $this->output->text());
	}

	/**
	 * Changing something mule watches rather than proves — a file excluded from
	 * coverage, a test nothing has run yet — means the store can no longer
	 * speak for the tree, so coverage runs. It may then find nothing stale
	 * after all, and says so rather than mutating for the sake of it.
	 *
	 * Unlike the configuration, such a file cannot change what a verdict
	 * asserts: nothing executes it, so nothing that was proven depended on it.
	 *
	 * @return void
	 */
	public function testRunsCoverageButMutatesNothingWhenOnlyAWatchedFileChanged(): void {
		$this->filesystem->given("/project/src/Unused.php", "<?php interface Unused {}");
		$this->gate()->full();
		$this->filesystem->given("/project/src/Unused.php", "<?php interface Unused { public function one(); }");
		$this->output = new RecordingOutput();
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);

		self::assertSame(0, $this->gate()->narrowed());

		self::assertSame(1, $this->processes->count(), "coverage should have run, and only coverage.");
		self::assertSame(
			"mule: 2 of 2 files verified, 0 stale.\n"
			. "mule: 2 of 2 lines covered (100.0%).\n"
			. "mule: every test here covers exactly one file, so no change can mark another file stale — see the README on coverage attribution.\n"
			. "mule: nothing to mutate, every file is verified against its own tests.",
			$this->output->text(),
		);
	}

	public function testNarrowsToTheFileWhoseSourceChanged(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");

		$this->gate()->narrowed();

		self::assertSame("--filter=src/Key.php", $this->filterOf(3));
		self::assertStringContainsString("mule: 1 of 2 files verified, 1 stale.", $this->output->text());
		self::assertStringContainsString("mule: 1 file(s) recorded at the full score", $this->output->text());
	}

	public function testNarrowsToEveryFileACoveringTestTouches(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/tests/GateTest.php", "<?php class GateTest { /* one assertion fewer */ }");

		$this->gate()->narrowed();

		self::assertSame("--filter=src/Gate.php", $this->filterOf(3));
	}

	public function testKeepsWhatTheStoreAlreadyVouchedForWhenBankingANarrowedRun(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");

		$this->gate()->narrowed();

		self::assertSame(["src/Gate.php", "src/Key.php"], $this->stored());
	}

	public function testRefusesToBankAFileTheTestsDoNotReachAllOf(): void {
		$this->givenKeyIsHalfCovered();

		self::assertSame(0, $this->gate()->full());

		self::assertSame(["src/Gate.php"], $this->stored());
	}

	public function testSaysWhereTheTestsStopInAFileItWillNotBank(): void {
		$this->givenKeyIsHalfCovered();

		$this->gate()->full();

		self::assertStringContainsString("mule: 0 of 2 files verified, 1 stale, 1 not covered in full.", $this->output->text());
		self::assertStringContainsString("mule: not banked until the tests reach them:", $this->output->text());
		self::assertStringContainsString("  src/Key.php — one() lines 12-19, 3 unreached", $this->output->text());
	}

	public function testKeepsMutatingAFileItCannotBankHoweverOftenItRuns(): void {
		$this->givenKeyIsHalfCovered();
		$this->gate()->full();

		$this->gate()->narrowed();

		self::assertSame("--filter=src/Key.php", $this->filterOf(3));
		self::assertStringContainsString("mule: 1 of 2 files verified, 0 stale, 1 not covered in full.", $this->output->text());
	}

	/**
	 * A narrowed run reads a partial report, which cannot say what the
	 * configuration is — so banking one must carry forward what the store
	 * already recorded rather than replacing it with nothing. Lose it and the
	 * next run finds no configuration stored, cannot match it against the one
	 * it measures, and re-proves the whole project.
	 *
	 * @return void
	 */
	public function testKeepsTheRecordedConfigurationWhenBankingANarrowedRun(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");

		$this->gate()->narrowed();

		self::assertSame(
			["composer.json", "mule.json"],
			array_keys($this->storedSnapshot()->configuration()),
		);
	}

	/**
	 * A narrowed run measures the files it needs and no others, so the lines in
	 * the rest of the project have to come from the store. `Gate.php` has twenty
	 * of them and is not re-measured; reporting the subset's own report alone
	 * would say the project is one line long.
	 *
	 * @return void
	 */
	public function testReportsTheWholeProjectsCoverageAfterANarrowedRun(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();
		$this->givenGateIsTwentyLinesLong();
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");
		$this->output = new RecordingOutput();

		$this->gate()->narrowed();

		self::assertSame(
			["tests/KeyTest.php"],
			array_slice($this->processes->command(2), 4),
			"only the one test should have run.",
		);
		self::assertStringContainsString("mule: 21 of 21 lines covered (100.0%).", $this->output->text());
	}

	public function testReportsTheCoverageItMeasuredWhenTheStoreCannotBeUsed(): void {
		$this->givenGateIsTwentyLinesLong();

		$this->gate()->full();

		self::assertStringContainsString("mule: 21 of 21 lines covered (100.0%).", $this->output->text());
	}

	public function testReportsTheLinesTheTestsDoNotReach(): void {
		$this->givenKeyIsHalfCovered();

		$this->gate()->full();

		self::assertStringContainsString("mule: 2 of 5 lines covered (40.0%).", $this->output->text());
	}

	/**
	 * The store answers with no coverage run and no engine, so the lines it
	 * reports are the ones it recorded the last time anything measured them.
	 *
	 * @return void
	 */
	public function testReportsTheStoredCoverageWithoutMeasuringAnything(): void {
		$this->givenGateIsTwentyLinesLong();
		$this->gate()->full();
		$this->output = new RecordingOutput();
		$started = $this->processes->count();

		$this->gate()->status();

		self::assertSame($started, $this->processes->count(), "the store should have answered alone.");
		self::assertStringContainsString("mule: 21 of 21 lines covered (100.0%).", $this->output->text());
	}

	/**
	 * A store from before mule recorded any of this still answers for the files
	 * it vouches for, and says nothing about lines it never measured.
	 *
	 * @return void
	 */
	public function testSaysNothingAboutCoverageWhenTheStoreRecordedNone(): void {
		$this->gate()->full();
		$this->givenTheStoreRecordedNoCoverage();
		$this->output = new RecordingOutput();

		self::assertSame(0, $this->gate()->status());

		self::assertSame(
			"mule: 2 of 2 files verified, 0 stale.\n"
			. "mule: every test here covers exactly one file, so no change can mark another file stale — see the README on coverage attribution.",
			$this->output->text(),
		);
	}

	/**
	 * The one assertion mule makes about coverage, and it is a project's to ask
	 * for. Reported after the verdicts are banked, never instead of them: the
	 * mutation work was done and proven, and discarding it because a separate
	 * assertion failed would mean paying for it again next run.
	 *
	 * @return void
	 */
	public function testFailsWhenCoverageIsBelowWhatTheProjectAsksFor(): void {
		$this->filesystem->given("/project/mule.json", '{"min-coverage": 100}');
		$this->givenKeyIsHalfCovered();

		self::assertSame(1, $this->gate()->full());

		self::assertStringContainsString("mule: 2 of 5 lines covered (40.0%).", $this->output->text());
		self::assertStringContainsString("mule: that is below the 100% this project asks for.", $this->output->text());
		self::assertSame(["src/Gate.php"], $this->stored(), "and it still banks what it proved.");
	}

	public function testPassesWhenCoverageIsAtWhatTheProjectAsksFor(): void {
		$this->filesystem->given("/project/mule.json", '{"min-coverage": 100}');

		self::assertSame(0, $this->gate()->full());

		self::assertStringNotContainsString("below the", $this->output->text());
	}

	/**
	 * The store records how much of each file was reached, so the cheapest verb
	 * holds a project to its own minimum without measuring anything.
	 *
	 * @return void
	 */
	public function testHoldsAProjectToItsMinimumFromTheStoreAlone(): void {
		$this->filesystem->given("/project/mule.json", '{"min-coverage": 100}');
		$this->givenKeyIsHalfCovered();
		$this->gate()->full();
		$this->output = new RecordingOutput();

		self::assertSame(1, $this->gate()->status());
	}

	public function testAssertsNothingAboutCoverageWhenTheProjectAsksForNothing(): void {
		$this->givenKeyIsHalfCovered();

		self::assertSame(0, $this->gate()->full());
	}

	public function testWorksCoverageOutFromTheSuiteTheProjectNamed(): void {
		$this->filesystem->given("/project/mule.json", '{"tests": "unit"}');

		$this->gate()->full();

		self::assertSame([
			PHP_BINARY,
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
			"--testsuite=unit",
		], $this->processes->command(0));
	}

	/**
	 * The files are the stricter instruction of the two — they are the tests of
	 * whatever is outstanding, whichever suite they sit in — and a runner given
	 * both a suite and a file list has no sensible reading of them.
	 *
	 * @return void
	 */
	public function testDropsTheNamedSuiteOnceParticularTestsAreNamed(): void {
		$this->filesystem->given("/project/mule.json", '{"tests": "unit", "narrow": true}');
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");

		$this->gate()->narrowed();

		self::assertSame([
			PHP_BINARY,
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
			"tests/KeyTest.php",
		], $this->processes->command(2));
	}

	/**
	 * The engine cannot be driven by flags alone — it has no option for the
	 * directories to mutate — so a run that wrote no configuration would be a
	 * run the engine could not have worked from.
	 *
	 * @return void
	 */
	public function testHandsTheEngineAConfigurationRenderedFromMuleJson(): void {
		$this->filesystem->given("/project/mule.json", '{"mutators": {"boolean": true}}');

		$this->gate()->full();

		self::assertSame(
			'{"source":{"directories":["/project/src"]},"phpUnit":{"configDir":"/project"},"minMsi":100,"minCoveredMsi":100}',
			str_replace(["\n", "\t", " "], "", $this->processes->declaration),
		);
	}

	/**
	 * The engine's exclude list cannot be scoped to one source root, so a file
	 * sharing a relative path with an excluded one is mutated by nothing. It
	 * must not be banked as though something had been.
	 *
	 * @return void
	 */
	public function testBanksNothingForAFileTheEngineCannotBeAskedToMutate(): void {
		$this->filesystem
			->given("/project/composer.json", '{"autoload": {"psr-4": {"Acme\\\\": ["src", "lib"]}}}')
			->given("/project/mule.json", '{"exclude": ["lib/Key.php"]}')
			->given("/project/lib/Key.php", "<?php class LibKey {}");

		$this->gate()->full();

		self::assertSame(["src/Gate.php"], $this->stored());
	}

	public function testHasSomethingToMutateWhileAnythingIsNotCoveredInFull(): void {
		$this->givenKeyIsHalfCovered();
		$this->gate()->full();
		$this->output = new RecordingOutput();

		$this->gate()->narrowed();

		self::assertStringNotContainsString("nothing to mutate", $this->output->text());
	}

	public function testHandsTheEngineItsScopeSortedWhicheverWayItWasFound(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [
			"Gate.php" => ["executable" => 2, "executed" => 1, "methods" => []],
		]);

		$this->gate()->narrowed();

		// Stale finds Key.php, unproven finds Gate.php, and the engine is told
		// about them in path order rather than in the order they turned up.
		self::assertSame("--filter=src/Gate.php,src/Key.php", $this->filterOf(1));
	}

	public function testBanksOnlyTheProvenFilesOfANarrowedScope(): void {
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [
			"Gate.php" => ["executable" => 2, "executed" => 1, "methods" => []],
		]);

		$this->gate()->narrowed();

		self::assertStringContainsString("mule: 1 file(s) recorded at the full score", $this->output->text());
	}

	public function testAsksTheRunnerForOnlyTheTestsThatCouldMatter(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");

		$this->gate()->narrowed();

		self::assertSame([
			PHP_BINARY,
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
			"tests/KeyTest.php",
		], $this->processes->command(2));
	}

	public function testExpandsTheTestsPlaceholderToNothingWhenTheWholeSuiteRuns(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();

		$this->gate()->full();

		self::assertSame([
			PHP_BINARY,
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
		], $this->processes->command(0));
		self::assertSame(["src/Gate.php", "src/Key.php"], $this->stored(), "a whole run still reads the whole report.");
	}

	public function testAsksForTheTestsOfEverythingOutstanding(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();
		$this->givenKeyIsHalfCovered();
		$this->gate()->full();
		$this->filesystem->given("/project/src/Gate.php", "<?php class Gate { public function run() {} }");

		$this->gate()->narrowed();

		// Gate.php changed; Key.php was never provable. Both need the engine,
		// so both their tests have to run again.
		self::assertSame([
			PHP_BINARY,
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
			"tests/GateTest.php",
			"tests/KeyTest.php",
		], $this->processes->command(2));
		self::assertSame("--filter=src/Gate.php,src/Key.php", $this->filterOf(3));
	}

	/**
	 * The hazard a partial report carries: it still names every source file,
	 * and the ones whose tests were not asked for come back looking as though
	 * nothing covers them. Read whole, that would overwrite a good verdict
	 * with a wrong one.
	 *
	 * @return void
	 */
	public function testIgnoresWhatAPartialReportSaysAboutFilesItDidNotAskAbout(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");
		// What a subset report really looks like: Gate.php is still in it, with
		// its lines still executable and none of them executed, because the
		// test that runs it was not asked for.
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => [],
		], "/project/src", [], [
			"Gate.php" => [
				"executable" => 3,
				"executed" => 0,
				"methods" => [["name" => "run", "start" => 5, "end" => 11, "executable" => 3, "executed" => 0]],
			],
		]);

		$this->gate()->narrowed();

		self::assertSame(["src/Gate.php", "src/Key.php"], $this->stored());
		self::assertStringNotContainsString("not covered in full", $this->output->text());
		self::assertStringContainsString("mule: 1 of 2 files verified, 1 stale.", $this->output->text());
		self::assertStringContainsString("mule: 1 file(s) recorded at the full score", $this->output->text());
	}

	public function testRunsTheWholeSuiteWhenTheStoreCannotSayWhichTestsMatter(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();
		$this->gate()->full();
		$this->filesystem->given("/project/src/New.php", "<?php class New_ {}");
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
			"New.php" => ["Acme\\Tests\\GateTest::testTwo"],
		]);

		$this->gate()->narrowed();

		self::assertSame([
			PHP_BINARY,
			"./vendor/bin/phpunit",
			"--coverage-xml=" . self::RUN . "/coverage-xml",
			"--log-junit=" . self::RUN . "/junit.xml",
		], $this->processes->command(0), "a store that cannot describe the tree cannot name the tests either.");
		self::assertContains("src/New.php", $this->stored(), "and it has to read the whole report back.");
	}

	public function testNarrowsToEveryStaleFileRatherThanTheFirst(): void {
		$this->givenTheCoverageCommandSaysWhereTestsGo();
		$this->gate()->full();
		$this->filesystem->given("/project/src/Key.php", "<?php class Key { public function one() {} }");
		$this->filesystem->given("/project/src/Gate.php", "<?php class Gate { public function run() {} }");

		$this->gate()->narrowed();

		self::assertSame("--filter=src/Gate.php,src/Key.php", $this->filterOf(3));
	}

	/**
	 * The gate keys the tree, then spends minutes mutating it. A file saved in
	 * between was proven as one thing and would be recorded as another.
	 *
	 * @return void
	 */
	public function testDoesNotBankAFileThatChangedWhileTheEngineRan(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [], [
			"/project/src/Key.php" => "<?php class Key { public function savedMidRun() {} }",
		]);

		self::assertSame(0, $this->gate()->full());

		self::assertSame(["src/Gate.php"], $this->stored());
		self::assertStringContainsString(
			"mule: 1 file(s) changed while the gate ran and were not recorded.",
			$this->output->text(),
		);
	}

	public function testStillAnswersFromTheStoreAfterAFileIsDeleted(): void {
		$this->gate()->full();
		$this->filesystem->delete("/project/src/Key.php");
		$this->filesystem->delete("/project/tests/KeyTest.php");
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);
		$this->gate()->full();

		$this->output = new RecordingOutput();
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);

		self::assertSame(0, $this->gate()->narrowed());

		self::assertSame(0, $this->processes->count(), "the store should have answered alone.");
	}

	/**
	 * A file that appears *during* a run is not in the report the run worked
	 * from, so nothing about it is recorded — and the store it leaves behind
	 * says so rather than claiming to describe the whole tree. The next run
	 * finds a PHP file it has never heard of and goes back to the engine.
	 *
	 * This is the one way a run can bank a store that is already incomplete,
	 * and it costs a coverage run rather than a wrong answer.
	 *
	 * @return void
	 */
	public function testNoticesAFileThatAppearedWhileTheEngineRan(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [], [
			"/project/src/Appeared.php" => "<?php class Appeared {}",
		]);

		self::assertSame(0, $this->gate()->full());
		self::assertSame(["src/Gate.php", "src/Key.php"], $this->stored());

		$this->output = new RecordingOutput();
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);

		self::assertSame(0, $this->gate()->narrowed());

		self::assertSame(1, $this->processes->count(), "the store should not have answered on its own.");
	}

	/**
	 * Every file of every test that moved, not the first file of the last test.
	 *
	 * `KeyTest` runs two source files and `GateTest` a third, and both tests
	 * are edited while the engine runs: all three verdicts rest on bytes that
	 * are no longer there, so none of them is recorded.
	 *
	 * @return void
	 */
	public function testWithholdsEveryFileOfEveryTestThatChangedWhileTheEngineRan(): void {
		$this->filesystem->given("/project/src/Extra.php", "<?php class Extra {}");
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Extra.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [], [
			"/project/tests/KeyTest.php" => "<?php class KeyTest { /* one assertion fewer */ }",
			"/project/tests/GateTest.php" => "<?php class GateTest { /* one assertion fewer */ }",
		]);

		self::assertSame(0, $this->gate()->full());

		self::assertSame([], $this->stored());
		self::assertStringContainsString(
			"mule: 3 file(s) changed while the gate ran and were not recorded.",
			$this->output->text(),
		);
	}

	/**
	 * The same guard by the other route. The mutants in `src/Key.php` died
	 * against whatever `tests/KeyTest.php` said while the engine ran, so a
	 * verdict credited to the test as it was before is not one mule measured —
	 * and if the edit is undone, the store would vouch for bytes nothing
	 * proved.
	 *
	 * @return void
	 */
	public function testDoesNotBankAFileWhoseTestChangedWhileTheEngineRan(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [], [
			"/project/tests/KeyTest.php" => "<?php class KeyTest { /* one assertion fewer */ }",
		]);

		self::assertSame(0, $this->gate()->full());

		self::assertSame(["src/Gate.php"], $this->stored());
		self::assertStringContainsString(
			"mule: 1 file(s) changed while the gate ran and were not recorded.",
			$this->output->text(),
		);
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

		self::assertSame("mule: 2 of 2 files verified, 0 stale.\nmule: 2 of 2 lines covered (100.0%).\n" . "mule: every test here covers exactly one file, so no change can mark another file stale — see the README on coverage attribution.", $this->output->text());
		self::assertSame(2, $this->processes->count(), "status generated coverage it did not need.");
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

	public function testLeavesNoScratchDirectoryBehindWhenStatusHasToMeasure(): void {
		$this->gate()->full();
		$this->filesystem->given(
			"/project/composer.json",
			'{"name": "acme/thing", "autoload": {"psr-4": {"Acme\\\\": "src"}}}',
		);
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		]);

		self::assertSame(0, $this->gate()->status());

		self::assertSame(1, $this->processes->count(), "status should have had to generate coverage.");
		self::assertFalse($this->filesystem->exists(self::RUN));
	}

	public function testLeavesNoScratchDirectoryBehindWhenCoverageFails(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [], "/project/src", [4]);

		$this->gate()->full();

		self::assertFalse($this->filesystem->exists(self::RUN));
	}

	/**
	 * Opts the project into narrowed coverage runs.
	 *
	 * @return void
	 */
	private function givenTheCoverageCommandSaysWhereTestsGo(): void {
		$this->filesystem->given("/project/mule.json", json_encode([
			"narrow" => TRUE,
		], JSON_THROW_ON_ERROR));
	}

	/**
	 * Re-states the coverage run so that `Key.php` comes back with a method
	 * the tests do not enter.
	 *
	 * @return void
	 */
	private function givenKeyIsHalfCovered(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [
			"Key.php" => [
				"executable" => 4,
				"executed" => 1,
				"methods" => [["name" => "one", "start" => 12, "end" => 19, "executable" => 3, "executed" => 0]],
			],
		]);
	}

	/**
	 * Takes the section back out of the store, which is the shape every store
	 * written before mule recorded coverage is in.
	 *
	 * @return void
	 */
	private function givenTheStoreRecordedNoCoverage(): void {
		$stored = $this->storedSnapshot();

		$this->filesystem->given(self::STORE, StoreFormat::write(Snapshot::of(
			$stored->verified(),
			$stored->tests(),
			$stored->unproven(),
			$stored->watched(),
			$stored->configuration(),
		)));
	}

	/**
	 * Re-states the coverage run so that `Gate.php` is twenty executable lines
	 * every one of which ran, and `Key.php` the one line it was.
	 *
	 * @return void
	 */
	private function givenGateIsTwentyLinesLong(): void {
		$this->processes = new CoveringProcesses($this->filesystem, [
			"Key.php" => ["Acme\\Tests\\KeyTest::testOne"],
			"Gate.php" => ["Acme\\Tests\\GateTest::testOne"],
		], "/project/src", [], [
			"Gate.php" => ["executable" => 20, "executed" => 20, "methods" => []],
		]);
	}

	/**
	 * The scope one engine run was narrowed to, found rather than counted to:
	 * the engine's command line is long and its order is not what is being
	 * asserted.
	 *
	 * @param int $index
	 * @return string
	 */
	private function filterOf(int $index): string {
		foreach($this->processes->command($index) as $argument) {
			if(str_starts_with($argument, "--filter=")) {
				return $argument;
			}
		}

		return "";
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
		], ["/project/src", "/project/tests"]);
		$universe = new Universe($project, $this->filesystem, $classes);

		$configuration = Configuration::load($project, $this->filesystem, $this->processes);

		return new Gate(
			$project,
			$configuration,
			new Keying($project, $this->filesystem, $classes, $universe, $this->declared(), $configuration),
			new Freshness($project, $this->filesystem, $universe, $this->declared()),
			new Store($project, $this->filesystem, $random),
			new Measurement($configuration),
			new Infection($configuration),
			new Declaration($configuration, $project->root),
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
		return $this->storedSnapshot()->paths();
	}

	/**
	 * What the store holds, read back as a snapshot.
	 *
	 * @return Snapshot
	 */
	private function storedSnapshot(): Snapshot {
		$store = new Store(
			Project::containing("/project", $this->filesystem),
			$this->filesystem,
			new FixedRandom(),
		);

		return $store->read();
	}

	/**
	 * @return Declared
	 */
	private function declared(): Declared {
		return new Declared(Project::containing("/project", $this->filesystem), $this->filesystem);
	}
}
