<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Closure;
use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\Coverage\Soundness;
use Kristos80\Mule\Engine\Declaration;
use Kristos80\Mule\Engine\Gate;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Commands;
use Kristos80\Mule\Runner\Coverage;
use Kristos80\Mule\Runner\Engine;
use Kristos80\Mule\Runner\Finding;
use Kristos80\Mule\Runner\Run;
use Kristos80\Mule\Runner\Shards;
use Kristos80\Mule\Runner\Universe;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\RecordingReports;
use Kristos80\Mule\Tests\Support\ScriptedProcesses;
use Kristos80\Mule\Tests\Support\StubClasses;
use Kristos80\Mule\Tests\Support\StubLines;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use function basename;
use function count;
use function json_encode;
use function str_ends_with;
use const JSON_THROW_ON_ERROR;

/**
 * The whole run, through in-memory ports.
 *
 * **Nothing here is a mock.** The store, the shard cache, the merge, the keys and
 * the gate are the real ones; only the filesystem, the processes, the autoloader,
 * the parser and the report renderer are stood in for. What is being tested is the
 * decision a run makes, and the decision is made out of those parts together.
 */
final class RunTest extends TestCase {

	private const string PAYLOAD = "/p/.mule/cache/capture.cov";

	private const string JUNIT = "/p/.mule/cache/junit.xml";

	private const string LOG = "/p/.mule/cache/outcome.json";

	private InMemoryFilesystem $filesystem;

	private ScriptedProcesses $processes;

	/**
	 * Files the engine should report an escaped mutant for rather than a kill.
	 *
	 * @var list<string>
	 */
	private array $escaping = [];

	/**
	 * Something to do between the engine starting and its log being read, so the
	 * stale-edit check has a case.
	 *
	 * @var Closure(): void|null
	 */
	private ?Closure $meanwhile = NULL;

	/**
	 * The only files the engine should file a mutant against, whatever it was
	 * asked about — which is how a file the engine quietly says nothing about is
	 * arranged (G1).
	 *
	 * @var list<string>|null
	 */
	private ?array $onlyFiling = NULL;

	/**
	 * What the parser says is executable, which is what the coverage floor is
	 * counted against.
	 *
	 * @var array<string, list<int>>
	 */
	private array $executable = ["/p/src/A.php" => [3], "/p/src/B.php" => [4], "/p/src/Hub.php" => [5]];

	/**
	 * Test classes that execute no instrumented source at all (M16) — a pure enum
	 * test, or one that only asserts about a fixture.
	 *
	 * @var list<string>
	 */
	private array $silent = ["Ccc"];

	/**
	 * The one line each test class runs on its own, besides the shared `Hub.php`.
	 * `CccTest` has none: it executes no instrumented source at all (M16).
	 */
	private const array OWN = ["Aaa" => ["A.php" => 3], "Bbb" => ["B.php" => 4], "Ccc" => []];

	/**
	 * Whether `AaaTest` has also started covering `B.php` — attribution changing
	 * ownership rather than appearing or disappearing.
	 */
	private bool $shared = FALSE;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem(
			files: [
				"/p/src/A.php" => "<?php // a",
				"/p/src/B.php" => "<?php // b",
				"/p/src/Hub.php" => "<?php // hub",
				"/p/tests/Unit/AaaTest.php" => "<?php // aaa",
				"/p/tests/Unit/BbbTest.php" => "<?php // bbb",
			],
			directories: ["/p/src", "/p/tests/Unit"],
		);
		$this->processes = new ScriptedProcesses($this->answering());
	}

	/**
	 * PHPUnit leaves a capture and a JUnit log; the engine leaves its verdicts.
	 * Which of the two is being started is read off the command, the way a real
	 * process would be told apart by what it is.
	 *
	 * @return Closure(list<string>): array{status: int, output: string}
	 */
	private function answering(): Closure {
		return $this->answered(...);
	}

	/**
	 * @param list<string> $command
	 * @return array{status: int, output: string}
	 */
	private function answered(array $command): array {
		if(in_array("vendor/bin/phpunit", $command, TRUE)) {
			$this->capturing($command);
		} else {
			$this->mutating($command);
		}

		return ["status" => 0, "output" => ""];
	}

	/**
	 * @param list<string> $command
	 * @return void
	 */
	private function capturing(array $command): void {
		$last = $command[count($command) - 1];
		$only = str_ends_with($last, "Test.php") ? [substr(basename($last), 0, 3)] : $this->classes();
		// **The JUnit log lists every class PHPUnit ran**, whether or not it
		// executed any instrumented source — attribution is the other file. A fake
		// that dropped the silent ones would make mule look like it had lost a
		// timing it never had any business losing.
		$this->filesystem->payloads[self::PAYLOAD] = $this->payload(array_values(array_diff($only, $this->silent)));
		$this->filesystem->files[self::JUNIT] = $this->junit($only);
	}

	/**
	 * The test classes the tree has, which is however many test files are in it.
	 *
	 * @return list<string>
	 */
	private function classes(): array {
		$classes = [];

		foreach(["Aaa", "Bbb", "Ccc"] as $class) {
			if($this->filesystem->exists("/p/tests/Unit/{$class}Test.php")) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	/**
	 * @param list<string> $command
	 * @return void
	 */
	private function mutating(array $command): void {
		$log = [
			"stats" => [
				"skippedCount" => 0,
				"timeOutCount" => 0,
				"syntaxErrorCount" => 0,
				"ignoredCount" => 0,
				"killedByStaticAnalysisCount" => 0,
			],
			"killed" => [],
			"escaped" => [],
		];

		foreach($this->onlyFiling ?? $this->mutated($command) as $file) {
			$kind = in_array($file, $this->escaping, TRUE) ? "escaped" : "killed";
			$log[$kind][] = ["mutator" => ["originalFilePath" => "/p/{$file}"]];
		}

		$this->filesystem->files[self::LOG] = (string) json_encode($log, JSON_THROW_ON_ERROR);

		if($this->meanwhile !== NULL) {
			($this->meanwhile)();
		}
	}

	/**
	 * What the engine was asked about: the filter, or everything.
	 *
	 * @param list<string> $command
	 * @return list<string>
	 */
	private function mutated(array $command): array {
		foreach($command as $argument) {
			if(str_starts_with($argument, "--filter=")) {
				return explode(",", substr($argument, strlen("--filter=")));
			}
		}

		$everything = [];

		foreach(array_keys($this->filesystem->files) as $path) {
			if(str_starts_with($path, "/p/src/")) {
				$everything[] = substr($path, strlen("/p/"));
			}
		}

		return $everything;
	}

	/**
	 * `AaaTest` runs `A.php` and the shared `Hub.php`; `BbbTest` runs `B.php` and
	 * `Hub.php` too. So `Hub.php` is a dependency of both verdicts, and an edit to
	 * `A.php` reaches a file that never mentions it — which is the breadth of the
	 * key, and the thing a narrower one would get wrong.
	 *
	 * **The files are listed out of order on purpose**, because a report's order is
	 * whichever one php-code-coverage happened to produce and nothing mule records
	 * may depend on it.
	 *
	 * @param list<string> $classes Either or both of "Aaa" and "Bbb".
	 * @return array<string, mixed>
	 */
	private function payload(array $classes): array {
		$coverage = new ProcessedCodeCoverageData();
		$ids = [];
		$results = [];
		$lines = ["Hub.php" => [5 => []], "B.php" => [4 => []], "A.php" => [3 => []]];

		foreach($classes as $index => $class) {
			$ids[$index] = "{$class}Test::testOne";
			$results["{$class}Test::testOne"] = ["size" => "unknown", "status" => "success", "time" => 0.5];
			$lines["Hub.php"][5][$index] = 1;

			foreach(self::OWN[$class] as $file => $line) {
				$lines[$file][$line][$index] = 1;
			}

			if($class === "Aaa" && $this->shared) {
				$lines["B.php"][4][$index] = 1;
			}
		}

		$coverage->setTestIds($ids);
		$coverage->setLineCoverage($lines);

		return [
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/p/src",
			"codeCoverage" => $coverage,
			"testResults" => $results,
		];
	}

	/**
	 * @param list<string> $classes
	 * @return string
	 */
	private function junit(array $classes): string {
		$suites = "";

		foreach($classes as $class) {
			$suites .= "<testsuite name=\"{$class}Test\" file=\"/p/tests/Unit/{$class}Test.php\" time=\"1.5\"/>";
		}

		return '<?xml version="1.0"?><testsuites>' . $suites . "</testsuites>";
	}

	/**
	 * @param non-empty-string $toolchain
	 * @param array<string, mixed> $settings
	 * @return Run
	 */
	private function mule(string $toolchain = "tc", array $settings = []): Run {
		$configuration = Configuration::of(["tests" => "tests/Unit", ...$settings]);
		$project = new Project($this->filesystem, "/p");
		$commands = new Commands($configuration, "/usr/bin/php", "");

		return new Run(
			new Universe($this->filesystem, $project, $configuration),
			new Coverage(
				$this->filesystem,
				$this->processes,
				new StubClasses([
					"AaaTest" => "/p/tests/Unit/AaaTest.php",
					"BbbTest" => "/p/tests/Unit/BbbTest.php",
					"CccTest" => "/p/tests/Unit/CccTest.php",
				]),
				$commands,
				new Shards($this->filesystem, "/p/.mule/cache"),
				$configuration,
				$project,
				".mule/cache",
			),
			new Soundness(new StubLines($this->executable), $project, $configuration),
			new Engine(
				$this->filesystem,
				$this->processes,
				$commands,
				new Report($this->filesystem, new RecordingReports()),
				new Declaration($configuration, ".mule/cache/outcome.json", ".mule/cache/tmp"),
				$project,
				".mule/cache",
			),
			new Store($this->filesystem, "/p/.mule/verified.json"),
			new Gate(),
			$toolchain,
		);
	}

	/**
	 * A test record as its touch lists alone, so a whole-map comparison reads as
	 * what each test runs rather than as object identity.
	 *
	 * @param array<string, Footprint> $tests
	 * @return array<string, list<string>>
	 */
	private function touches(array $tests): array {
		$touches = [];

		foreach($tests as $test => $footprint) {
			$touches[$test] = $footprint->touches;
		}

		return $touches;
	}

	/**
	 * @return list<list<string>>
	 */
	private function commands(): array {
		return array_column($this->processes->started, "command");
	}

	public function testAColdRunProvesEverything(): void {
		$finding = ($this->mule())();

		self::assertTrue($finding->measured);
		self::assertTrue($finding->holds());
		self::assertSame(["src/A.php", "src/B.php", "src/Hub.php"], array_keys($finding->store->verdicts));
	}

	/**
	 * Sorted, and complete: what a test executed is the half that makes the next
	 * run's question cheap, so the order it was recorded in must not be the order
	 * a report happened to list it.
	 *
	 * @return void
	 */
	public function testAColdRunRecordsWhatEachTestExecuted(): void {
		self::assertSame(
			[
				"tests/Unit/AaaTest.php" => ["src/A.php", "src/Hub.php"],
				"tests/Unit/BbbTest.php" => ["src/B.php", "src/Hub.php"],
			],
			$this->touches(($this->mule())()->store->tests),
		);
	}

	/**
	 * A cold run asks about every source file, and naming four hundred of them is
	 * a command line where an absent flag says the same thing — whereas an empty
	 * filter is one that matches nothing.
	 *
	 * @return void
	 */
	public function testAColdRunPassesNoFilter(): void {
		($this->mule())();

		self::assertNotContains("--filter=", $this->commands()[1]);
	}

	/**
	 * **The cheap question, and the whole point of the tool.** A second run with
	 * nothing changed starts no process at all: not the engine, and not even a
	 * coverage capture.
	 *
	 * @return void
	 */
	public function testASecondRunWithNothingChangedMeasuresNothing(): void {
		($this->mule())();
		$this->processes->started = [];

		$finding = ($this->mule())();

		self::assertFalse($finding->measured);
		self::assertSame([], $this->commands());
		self::assertTrue($finding->holds());
	}

	/**
	 * **The invariant the whole cache rests on.** `Run` keys a file out of a fresh
	 * coverage pass and `Cache\Standing` keys it out of the store; if the two
	 * disagree by so much as a label, every verdict is banked under a key nothing
	 * can ever match and the cache simply never hits — silently, with every run
	 * still correct and still as slow as the first.
	 *
	 * @return void
	 */
	public function testTheKeyBankedIsTheKeyTheStoreWillRecompute(): void {
		($this->mule())();

		self::assertSame("the store describes this tree", ($this->mule())()->standing->because);
	}

	public function testEditingAFilePutsItBackInTheQueue(): void {
		($this->mule())();
		$this->filesystem->files["/p/src/A.php"] = "<?php // a, differently";
		$this->processes->started = [];

		// `Hub.php` comes too: it shares a test with `A.php`, so that test's
		// behaviour — and therefore every `Hub.php` verdict proven against it —
		// depends on `A.php`. The edge runs through the test, not the source.
		self::assertSame(["src/A.php", "src/Hub.php"], ($this->mule())()->standing->outstanding);
	}

	/**
	 * Only the dirty test file is re-measured, and only the outstanding source
	 * files are handed to the engine. `BbbTest` is left alone even though it covers
	 * `Hub.php`, because its shard was measured against bytes that have not moved.
	 *
	 * @return void
	 */
	public function testEditingAFileReMeasuresOnlyWhatCoversIt(): void {
		($this->mule())();
		$this->filesystem->files["/p/src/A.php"] = "<?php // a, differently";
		$this->processes->started = [];

		($this->mule())();

		self::assertSame(
			[
				["/usr/bin/php", "vendor/bin/phpunit", "--no-output",
					"--coverage-php=/p/.mule/cache/capture.cov", "--log-junit=/p/.mule/cache/junit.xml",
					"tests/Unit/AaaTest.php"],
			],
			array_slice($this->commands(), 0, 1),
		);
		self::assertContains("--filter=src/A.php,src/Hub.php", $this->commands()[1]);
	}

	/**
	 * **Editing a test re-measures it too.** Its own bytes decide what it covers,
	 * so a run that re-keyed the files it touches without re-measuring it would key
	 * them against coverage from the version before the edit.
	 *
	 * @return void
	 */
	public function testEditingATestReMeasuresThatTest(): void {
		($this->mule())();
		$this->filesystem->files["/p/tests/Unit/BbbTest.php"] = "<?php // bbb, differently";
		$this->processes->started = [];

		($this->mule())();

		self::assertContains("tests/Unit/BbbTest.php", $this->commands()[0]);
		self::assertContains("--filter=src/B.php,src/Hub.php", $this->commands()[1]);
	}

	/**
	 * **Deleting the only test for a file un-proves that file.** Its shard is
	 * still in the cache, and reassembling it would hand the engine a report
	 * naming a test that cannot be run — whereupon Infection runs every mutant
	 * against a missing test file, the run fails, and a failing run scores as a
	 * kill. Dropping it leaves `B.php` looking unreached, which is what it is.
	 *
	 * @return void
	 */
	public function testDeletingATestUnprovesWhatItCovered(): void {
		($this->mule())();
		unset($this->filesystem->files["/p/tests/Unit/BbbTest.php"]);
		$this->processes->started = [];

		$finding = ($this->mule())();

		self::assertSame(["src/B.php", "src/Hub.php"], $finding->standing->outstanding);
		self::assertSame(["src/A.php", "src/Hub.php"], array_keys($finding->store->verdicts));
		self::assertSame(["src/B.php"], $finding->store->unproven);
	}

	/**
	 * And it stays un-proven, rather than being asked about for ever. A deleted
	 * test that kept appearing among a file's covering tests would give it a key
	 * nothing can reproduce.
	 *
	 * @return void
	 */
	public function testADeletedTestIsForgottenRatherThanRemembered(): void {
		($this->mule())();
		unset($this->filesystem->files["/p/tests/Unit/BbbTest.php"]);
		($this->mule())();

		self::assertSame(["tests/Unit/AaaTest.php"], array_keys(($this->mule())()->store->tests));
	}

	/**
	 * **A file nobody has heard of cannot be reasoned about from digests**, so the
	 * run goes wide rather than guessing what it covers (G9).
	 *
	 * @return void
	 */
	public function testANewFileMakesTheRunWhole(): void {
		($this->mule())();
		$this->filesystem->files["/p/src/C.php"] = "<?php // c";
		$this->processes->started = [];

		$finding = ($this->mule())();

		self::assertFalse($finding->standing->usable);
		self::assertStringContainsString("never heard of", $finding->standing->because);
		self::assertNotContains("tests/Unit/AaaTest.php", $this->commands()[0]);
	}

	/**
	 * A verdict is reusable only under an equivalent verification environment, so a
	 * changed toolchain invalidates the whole store at once — which is correct
	 * rather than an error: nothing in it was proven under the new arrangement.
	 *
	 * @return void
	 */
	public function testADifferentToolchainThrowsEverythingAway(): void {
		($this->mule())();
		$this->processes->started = [];

		$finding = ($this->mule("a different gate"))();

		self::assertFalse($finding->standing->usable);
		self::assertStringContainsString("verification environment has changed", $finding->standing->because);
		self::assertCount(2, $this->commands());
	}

	public function testAskingForEverythingAnyway(): void {
		($this->mule())();
		$this->processes->started = [];

		$finding = ($this->mule())(whole: TRUE);

		self::assertTrue($finding->measured);
		self::assertNotContains("tests/Unit/AaaTest.php", $this->commands()[0]);
		self::assertNotContains("--filter=src/A.php", $this->commands()[1]);
	}

	/**
	 * **G8.** A file is proven only when every one of its mutants was killed by a
	 * test, and an escape is a finding the store records rather than forgets.
	 *
	 * @return void
	 */
	public function testAnEscapedMutantIsNotProven(): void {
		$this->escaping = ["src/B.php", "src/Hub.php"];

		$finding = ($this->mule())();

		self::assertFalse($finding->holds());
		self::assertSame(["src/B.php", "src/Hub.php"], $finding->store->unproven);
		self::assertSame(["src/B.php", "src/Hub.php"], $finding->escaped());
		self::assertSame(["src/A.php"], $finding->proved());
	}

	public function testEveryFileWhoseMutantsWereAllKilledIsProven(): void {
		$this->escaping = ["src/B.php"];

		$finding = ($this->mule())();

		self::assertSame(["src/A.php", "src/Hub.php"], $finding->proved());
		self::assertSame(["src/B.php"], $finding->escaped());
	}

	/**
	 * A file an earlier run could not prove is still not proven, so a later run
	 * that happened to touch none of those files has not made the project green.
	 *
	 * @return void
	 */
	public function testAnEscapeOutlivesTheRunThatFoundIt(): void {
		$this->escaping = ["src/B.php"];
		($this->mule())();

		self::assertFalse(($this->mule())()->holds());
	}

	public function testAnEscapeIsRetriedUntilItIsFixed(): void {
		$this->escaping = ["src/B.php"];
		($this->mule())();
		$this->escaping = [];

		self::assertTrue(($this->mule())()->holds());
	}

	/**
	 * **The stale-edit check.** Somebody who edits a file while the engine runs
	 * gets a verdict belonging to neither version of it: the mutants came from the
	 * old bytes and the key would be written against the new ones. Leaving it out of
	 * the store means the next run re-proves it; claiming it is the one outcome
	 * nothing can recover from.
	 *
	 * @return void
	 */
	public function testAFileEditedWhileTheEngineRanIsNotBanked(): void {
		$this->meanwhile = function (): void {
			$this->filesystem->files["/p/src/A.php"] = "<?php // edited mid-run";
		};

		$finding = ($this->mule())();

		// `Hub.php` goes with it: its verdict rests on `A.php` through the test they
		// share, so its key moved too.
		self::assertSame(["src/A.php", "src/Hub.php"], $finding->abandoned);
		self::assertSame(["src/B.php"], array_keys($finding->store->verdicts));
	}

	public function testAFileEditedWhileTheEngineRanIsProvenNextTime(): void {
		$this->meanwhile = function (): void {
			$this->filesystem->files["/p/src/A.php"] = "<?php // edited mid-run";
		};
		($this->mule())();
		$this->meanwhile = NULL;

		self::assertSame(["src/A.php", "src/Hub.php"], ($this->mule())()->standing->outstanding);
	}

	/**
	 * **G1.** A file the engine was asked about and filed nothing against is the
	 * measured failure: stale coverage leaves its mutable lines looking unreached,
	 * so no mutants are generated, the file vanishes from the report, and the run
	 * exits 0 having proven nothing about it.
	 *
	 * @return void
	 */
	public function testAFileTheEngineFiledNothingAgainst(): void {
		$this->onlyFiling = ["src/A.php"];

		$this->expectExceptionMessage('The engine filed no mutants against "src/B.php"');

		($this->mule())();
	}

	public function testAnExcludedFileIsNeitherKeyedNorMutated(): void {
		$finding = ($this->mule(settings: ["exclude" => ["src/B.php"]]))();

		self::assertSame(["src/A.php", "src/Hub.php"], array_keys($finding->store->verdicts));
		self::assertSame([], $finding->store->unproven);
	}

	/**
	 * **An excluded file is not in the test record either**, and the consequence is
	 * worth stating: its digest is then tracked nowhere, so editing it invalidates
	 * nothing. That is what `exclude` means — a file whose bytes can change a
	 * verdict belongs in `support`, where a change invalidates the store outright.
	 *
	 * Were it recorded, its digest would be in every shard that covered it and in
	 * no digest map, so every shard would read as stale on every run.
	 *
	 * @return void
	 */
	public function testAnExcludedFileIsNotInTheTestRecord(): void {
		$mule = $this->mule(settings: ["exclude" => ["src/B.php"]]);
		$mule();
		$this->processes->started = [];

		$finding = $mule();

		self::assertSame(
			["tests/Unit/BbbTest.php" => ["src/Hub.php"]],
			array_slice($this->touches($finding->store->tests), 1),
		);
		self::assertFalse($finding->measured);
	}

	/**
	 * **The gap the mutation gate cannot see.** Line 7 of `A.php` is executable
	 * and nothing runs it; if nothing on it is mutable the engine generates no
	 * mutant for it, so every mutant of the file is killed and it would bank as
	 * proven with a chunk never executed.
	 *
	 * It is a finding rather than a fault: the file is recorded unproven and the
	 * run exits 1, as against the hard failure a stale shard gets.
	 *
	 * @return void
	 */
	public function testAFileTheSuiteDoesNotReachIsNotProven(): void {
		$this->executable["/p/src/A.php"] = [3, 7];
		$this->executable["/p/src/B.php"] = [4, 8];

		$finding = ($this->mule())();

		self::assertFalse($finding->holds());
		self::assertSame(["src/A.php", "src/B.php"], $finding->store->unproven);
		self::assertSame(["src/Hub.php"], array_keys($finding->store->verdicts));
	}

	public function testTheFloorIsThePersonsChoice(): void {
		$this->executable["/p/src/A.php"] = [3, 7];

		self::assertTrue(($this->mule(settings: ["min-coverage" => 50]))()->holds());
	}

	/**
	 * **G3.** A report naming a line the file does not have is a shard describing
	 * a version of it that is gone, and that is a broken cache rather than a
	 * finding — so the run stops before the engine is handed it.
	 *
	 * @return void
	 */
	public function testAStaleShardStopsTheRunBeforeTheEngine(): void {
		$this->executable["/p/src/A.php"] = [];

		$this->expectExceptionMessage('The coverage for "src/A.php" names line(s) 3');

		($this->mule())();
	}

	/**
	 * Nothing was owed, so nothing was measured — and that is a different thing to
	 * say than "everything was re-proven", even though the store is the same.
	 *
	 * @return void
	 */
	public function testNothingOwedIsItsOwnKindOfAnswer(): void {
		($this->mule())();

		$finding = ($this->mule())();

		self::assertFalse($finding->measured);
		self::assertSame([], $finding->examined);
		self::assertSame([], $finding->abandoned);
		self::assertSame([], $finding->proved());
		self::assertInstanceOf(Finding::class, $finding);
	}

	/**
	 * **M16 is a real case, and a cold run must still record the test.** A test
	 * class that executes no instrumented source is absent from `testIds()`, so
	 * splitting produces nothing for it — but a test file on disk that no part of
	 * the store mentions reads as having appeared, which makes every run from then
	 * on a cold one.
	 *
	 * @return void
	 */
	public function testATestThatCoversNothingDoesNotForceAColdRunForEver(): void {
		$this->silent = ["Aaa"];
		($this->mule())();
		$this->processes->started = [];

		$finding = ($this->mule())();

		self::assertTrue($finding->standing->usable);
		self::assertSame("the store describes this tree", $finding->standing->because);
		self::assertArrayHasKey("tests/Unit/AaaTest.php", $finding->store->tests);
		self::assertSame([], $finding->store->tests["tests/Unit/AaaTest.php"]->touches);
	}

	/**
	 * **A file can acquire a covering test without changing itself.** Editing
	 * `AaaTest` so that it also runs `B.php` moves `B.php`'s closure — it now
	 * depends on `A.php` through the test the two share — while `B.php`'s own bytes
	 * and its recorded covering tests are both untouched.
	 *
	 * The invariant: a run with no edits between it and the one before must find
	 * nothing owed. If the run that noticed the new ownership banked no new key for
	 * `B.php`, the *next* run re-proves it for no reason — and in between, `B.php`
	 * carries a verdict keyed on fewer tests than cover it.
	 *
	 * @return void
	 */
	public function testAFileThatAcquiresACoveringTestIsReKeyedAtOnce(): void {
		($this->mule())();
		$this->shared = TRUE;
		$this->filesystem->files["/p/tests/Unit/AaaTest.php"] = "<?php // aaa, now touching B too";
		($this->mule())();
		$this->processes->started = [];

		$finding = ($this->mule())();

		self::assertSame([], $finding->standing->outstanding);
		self::assertFalse($finding->measured);
		self::assertSame([], $this->commands());
	}

	/**
	 * **A test file that appears and covers nothing costs one capture, not one
	 * mutation run.** The store had never heard of it, so the run cannot be partial
	 * — but once measured it turns out to move no key at all, and there is nothing
	 * to ask the engine. Recording it is what stops the next run going wide for the
	 * same reason.
	 *
	 * @return void
	 */
	public function testATestFileThatAppearsAndCoversNothingAsksTheEngineNothing(): void {
		($this->mule())();
		$this->filesystem->files["/p/tests/Unit/CccTest.php"] = "<?php // ccc, asserts about a fixture";
		$this->processes->started = [];

		$finding = ($this->mule())();

		self::assertFalse($finding->standing->usable);
		self::assertCount(1, $this->commands());
		self::assertSame([], $finding->examined);
		self::assertTrue($finding->holds());
		self::assertSame([], $finding->store->tests["tests/Unit/CccTest.php"]->touches);
	}

	public function testAndTheRunAfterThatIsCheapAgain(): void {
		($this->mule())();
		$this->filesystem->files["/p/tests/Unit/CccTest.php"] = "<?php // ccc";
		($this->mule())();
		$this->processes->started = [];

		self::assertFalse(($this->mule())()->measured);
		self::assertSame([], $this->commands());
	}

	/**
	 * **A file nobody has heard of makes the *capture* whole, not the mutation
	 * run.** What cannot be worked out from digests is the new file's coverage; once
	 * a coverage pass has happened, what moved is exactly knowable, and re-proving
	 * the files that did not move would be work nobody asked for.
	 *
	 * @return void
	 */
	public function testANewSourceFileIsProvenWithoutReProvingTheRest(): void {
		($this->mule())();
		$this->filesystem->files["/p/src/C.php"] = "<?php // c";
		$this->executable["/p/src/C.php"] = [];
		$this->processes->started = [];

		($this->mule())();

		self::assertNotContains("tests/Unit/AaaTest.php", $this->commands()[0]);
		self::assertContains("--filter=src/C.php", $this->commands()[1]);
	}

	/**
	 * **A test that covered nothing and now covers something.** Its digest belongs
	 * to no source file's key, because it touched nothing — so recomputing every
	 * key from the store finds nothing moved, and the cheap question answers
	 * "nothing is owed" while the coverage picture has changed underneath it.
	 *
	 * Not a false verdict: adding a covering test cannot un-kill a mutant. But it
	 * is mule claiming the store describes this tree when it does not, and that
	 * claim is the only thing the fast path has to sell.
	 *
	 * @return void
	 */
	public function testATestThatStartsCoveringSomethingIsNoticed(): void {
		($this->mule())();
		$this->filesystem->files["/p/tests/Unit/CccTest.php"] = "<?php // ccc, asserts about a fixture";
		($this->mule())();
		$this->silent = [];
		$this->filesystem->files["/p/tests/Unit/CccTest.php"] = "<?php // ccc, now running Hub";
		$this->processes->started = [];

		$finding = ($this->mule())();

		self::assertTrue($finding->measured);
		self::assertSame(["src/Hub.php"], $finding->store->tests["tests/Unit/CccTest.php"]->touches);
	}
}
