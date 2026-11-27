<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Commands;
use Kristos80\Mule\Runner\Coverage;
use Kristos80\Mule\Runner\Shards;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\ScriptedProcesses;
use Kristos80\Mule\Tests\Support\StubClasses;
use Kristos80\Mule\Tests\Support\RecordingProgress;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use function count;
use function hash;
use function str_ends_with;
use function substr;

final class CoverageTest extends TestCase {

	private const string PAYLOAD = "/p/.mule/cache/capture.cov";

	private const string JUNIT = "/p/.mule/cache/junit.xml";

	private InMemoryFilesystem $filesystem;

	private ScriptedProcesses $processes;

	private RecordingProgress $progress;

	/**
	 * Test classes that execute no instrumented source at all (M16).
	 *
	 * @var list<string>
	 */
	private array $silent = [];

	/**
	 * Whether a capture of one test file comes back with two test classes in it —
	 * somebody having added a second class to a file that already had one.
	 */
	private bool $twoInOne = FALSE;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem(
			files: [
				"/p/tests/Unit/AaaTest.php" => "<?php // aaa",
				"/p/tests/Unit/BbbTest.php" => "<?php // bbb",
				"/p/src/A.php" => "<?php // a",
				"/p/src/B.php" => "<?php // b",
				"/p/src/Hub.php" => "<?php // hub",
			],
			directories: ["/p/src", "/p/tests/Unit"],
		);
		$this->processes = new ScriptedProcesses($this->answered(...));
		$this->progress = new RecordingProgress();
	}

	/**
	 * PHPUnit leaves a capture and a JUnit log. Which test file it was asked for is
	 * read off the command, so two captures in one warm run can answer differently.
	 *
	 * @param list<string> $command
	 * @return array{status: int, output: string}
	 */
	private function answered(array $command): array {
		$last = $command[count($command) - 1];
		$only = str_ends_with($last, "Test.php") && !$this->twoInOne
			? [$last === "tests/Unit/AaaTest.php" ? "Aaa" : "Bbb"]
			: ["Aaa", "Bbb"];

		// **The JUnit log lists every class PHPUnit ran**, whether or not it
		// executed any instrumented source — attribution is the other file. A fake
		// that dropped the silent ones would make mule look like it had lost a
		// timing it never had any business losing.
		$this->filesystem->payloads[self::PAYLOAD] = $this->payload(array_values(array_diff($only, $this->silent)));
		$this->filesystem->files[self::JUNIT] = $this->junit($only);

		return ["status" => 0, "output" => ""];
	}

	/**
	 * Every test file on disk, as `Runner\Universe` would hand it over.
	 *
	 * @return list<string>
	 */
	private function known(): array {
		return ["tests/Unit/AaaTest.php", "tests/Unit/BbbTest.php"];
	}

	/**
	 * `AaaTest` runs two lines of `A.php` and the shared `Hub.php`; `BbbTest` runs
	 * `B.php` and `Hub.php` too. Line 9 of `B.php` is executable and reached by
	 * nobody.
	 *
	 * **The files are listed out of order on purpose**, because a report's order is
	 * whichever one php-code-coverage happened to produce and nothing mule records
	 * may depend on it.
	 *
	 * @param list<string> $classes Either or both of "Aaa" and "Bbb".
	 * @return array<string, mixed>
	 */
	private function payload(array $classes = ["Aaa", "Bbb"]): array {
		$coverage = new ProcessedCodeCoverageData();
		$ids = [];
		$results = [];
		$lines = ["Hub.php" => [5 => []], "B.php" => [4 => [], 9 => []], "A.php" => [3 => [], 6 => []]];

		foreach($classes as $index => $class) {
			$ids[$index] = "{$class}Test::testOne";
			$results["{$class}Test::testOne"] = ["size" => "unknown", "status" => "success", "time" => 0.5];
			$lines["Hub.php"][5][$index] = 1;

			if($class === "Aaa") {
				$lines["A.php"][3][$index] = 1;
				$lines["A.php"][6][$index] = 1;

				continue;
			}

			$lines["B.php"][4][$index] = 1;
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
	private function junit(array $classes = ["Aaa", "Bbb"]): string {
		$suites = "";

		foreach($classes as $class) {
			$suites .= "<testsuite name=\"{$class}Test\" file=\"/p/tests/Unit/{$class}Test.php\" time=\"1.5\"/>";
		}

		return '<?xml version="1.0"?><testsuites>' . $suites . "</testsuites>";
	}

	/**
	 * What the store would hold after a cold run.
	 *
	 * @return array<string, Footprint>
	 */
	private function recorded(): array {
		return [
			"tests/Unit/AaaTest.php" => new Footprint(
				$this->filesystem->digest("/p/tests/Unit/AaaTest.php"),
				["src/A.php", "src/Hub.php"],
			),
			"tests/Unit/BbbTest.php" => new Footprint(
				$this->filesystem->digest("/p/tests/Unit/BbbTest.php"),
				["src/B.php", "src/Hub.php"],
			),
		];
	}

	/**
	 * The tree's digests, optionally with one file's moved — which is how a shard
	 * is made stale.
	 *
	 * @param string $dirty
	 * @return array<string, non-empty-string>
	 */
	private function digests(string $dirty = ""): array {
		$digests = [];

		foreach(array_keys($this->filesystem->files) as $path) {
			if(str_ends_with($path, ".php")) {
				$digests[substr($path, strlen("/p/"))] = $path === $dirty
					? hash("sha256", "moved")
					: $this->filesystem->digest($path);
			}
		}

		return $digests;
	}

	/**
	 * @param array<string, string> $places
	 * @param array<string, mixed> $settings
	 * @return Coverage
	 */
	private function coverage(
		array $places = [
			"AaaTest" => "/p/tests/Unit/AaaTest.php",
			"BbbTest" => "/p/tests/Unit/BbbTest.php",
		],
		array $settings = [],
	): Coverage {
		$configuration = Configuration::of(["tests" => "tests/Unit", ...$settings]);
		$project = new Project($this->filesystem, "/p");

		return new Coverage(
			$this->filesystem,
			$this->processes,
			new StubClasses($places),
			new Commands($configuration, "/usr/bin/php", ""),
			new Shards($this->filesystem, "/p/.mule/cache"),
			$configuration,
			$project,
			$this->progress,
			".mule/cache",
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

	/**
	 * **Cold is one capture and a split, never a capture per test.** Measured on a
	 * 613-file project: 30.8 s monolithic plus 2.9 s to split, against 61 s to
	 * capture each of 334 test files in its own process (M4).
	 *
	 * @return void
	 */
	public function testAColdRunStartsOneProcessForTheWholeSuite(): void {
		$this->coverage()->whole($this->known());

		self::assertSame(
			[["/usr/bin/php", "vendor/bin/phpunit", "--no-output", "--coverage-php=" . self::PAYLOAD,
				"--log-junit=" . self::JUNIT]],
			$this->commands(),
		);
	}

	public function testItRunsFromTheProjectRoot(): void {
		$this->coverage()->whole($this->known());

		self::assertSame("/p/", $this->processes->started[0]["directory"]);
	}

	/**
	 * What each test was seen to execute is the half that makes the cheap question
	 * cheap: the next run answers what is outstanding from this map and a few
	 * hundred digests, with no driver and no test process.
	 *
	 * Sorted, because the order a report lists files in is php-code-coverage's
	 * business and nothing mule records may depend on it.
	 *
	 * @return void
	 */
	public function testItRecordsWhatEachTestRan(): void {
		self::assertSame(
			$this->touches($this->recorded()),
			$this->touches($this->coverage()->whole($this->known())->tests),
		);
	}

	/**
	 * A file is in a test's footprint once, however many of its lines ran — and a
	 * line in the skeleton that nobody ran does not put it there at all, which is
	 * why `B.php` is absent from `AaaTest`'s.
	 *
	 * @return void
	 */
	public function testAFootprintNamesEachFileOnce(): void {
		$this->silent = ["Bbb"];

		self::assertSame(
			[
				"tests/Unit/AaaTest.php" => ["src/A.php", "src/Hub.php"],
				"tests/Unit/BbbTest.php" => [],
			],
			$this->touches($this->coverage()->whole($this->known())->tests),
		);
	}

	/**
	 * **An excluded file is left out, and the consequence is worth stating.** Its
	 * digest is then tracked nowhere, so editing it invalidates nothing — a file
	 * whose bytes can change a verdict belongs in `support` instead.
	 *
	 * @return void
	 */
	public function testAnExcludedFileIsNotInAFootprint(): void {
		self::assertSame(
			[
				"tests/Unit/AaaTest.php" => ["src/A.php"],
				"tests/Unit/BbbTest.php" => ["src/B.php"],
			],
			$this->touches($this->coverage(settings: ["exclude" => ["src/Hub.php"]])->whole($this->known())->tests),
		);
	}

	public function testAColdRunWritesOneShardPerTestFile(): void {
		$this->coverage()->whole($this->known());

		self::assertTrue($this->filesystem->exists("/p/.mule/cache/tests_Unit_AaaTest.php.json"));
		self::assertTrue($this->filesystem->exists("/p/.mule/cache/tests_Unit_BbbTest.php.json"));
	}

	/**
	 * A shard records the digests it was measured against — its own test file and
	 * every source file it ran — because that is what the next run compares to
	 * decide whether to re-measure it.
	 *
	 * @return void
	 */
	public function testAShardRecordsEverythingItWasMeasuredAgainst(): void {
		$this->coverage()->whole($this->known());
		$shards = new Shards($this->filesystem, "/p/.mule/cache");

		self::assertTrue($shards->read("tests/Unit/AaaTest.php")->matches($this->digests()));
		self::assertFalse($shards->read("tests/Unit/AaaTest.php")->matches($this->digests("/p/src/Hub.php")));
		self::assertFalse($shards->read("tests/Unit/AaaTest.php")->matches($this->digests("/p/src/A.php")));
		self::assertFalse(
			$shards->read("tests/Unit/AaaTest.php")->matches($this->digests("/p/tests/Unit/AaaTest.php")),
		);
	}

	/**
	 * **Machine-local, and it ignores itself.** The store beside it is committed,
	 * and one careless `git add .mule` is the whole difference — so mule writes the
	 * `.gitignore` on both paths rather than asking a consumer to.
	 *
	 * @return void
	 */
	public function testAColdRunMakesTheCacheIgnoreItself(): void {
		$this->coverage()->whole($this->known());

		self::assertSame("*\n", $this->filesystem->read("/p/.mule/cache/.gitignore"));
	}

	public function testAWarmRunMakesTheCacheIgnoreItself(): void {
		$this->coverage()->whole($this->known());
		$this->filesystem->remove("/p/.mule/cache/.gitignore");

		$this->coverage()->refresh($this->recorded(), $this->digests());

		self::assertSame("*\n", $this->filesystem->read("/p/.mule/cache/.gitignore"));
	}

	/**
	 * **Timings are keyed separately, from the one reference capture.** Summing
	 * them across shards inflates them and the engine then silently discards
	 * mutants that look slow (M12, M13).
	 *
	 * @return void
	 */
	public function testAColdRunBanksTheReferenceTimings(): void {
		self::assertSame([], $this->coverage()->whole($this->known())->timings->missing(["AaaTest", "BbbTest"]));
		self::assertTrue($this->filesystem->exists("/p/.mule/cache/timings.xml"));
	}

	/**
	 * **G6.** A test file failing during capture fails the run, with PHPUnit's
	 * output — there is nothing to measure once the suite is red, and measuring it
	 * anyway is how a verdict gets banked for a failing test.
	 *
	 * @return void
	 */
	public function testASuiteThatDoesNotPassStopsTheRun(): void {
		$this->processes = new ScriptedProcesses(
			static fn (): array => ["status" => 1, "output" => "AaaTest::testOne failed"],
		);

		$this->expectExceptionMessage(
			"The suite did not pass, so there is nothing to measure. /usr/bin/php vendor/bin/phpunit"
			. " --no-output --coverage-php=" . self::PAYLOAD . " --log-junit=" . self::JUNIT
			. "\nAaaTest::testOne failed",
		);

		$this->coverage()->whole($this->known());
	}

	/**
	 * A covering test the project's autoloader cannot place is a test whose edits
	 * would silently stop invalidating anything, which is worth a message rather
	 * than a shard filed under `FALSE`.
	 *
	 * @return void
	 */
	public function testACoveringTestNothingCanPlace(): void {
		$this->expectExceptionMessage(
			"The project's autoloader cannot place the covering test AaaTest, so mule cannot find its"
			. " file — and a test it cannot find is a test whose edits would stop invalidating"
			. " anything.",
		);

		$this->coverage(["BbbTest" => "/p/tests/Unit/BbbTest.php"])->whole($this->known());
	}

	/**
	 * **Warm is a capture per dirty test and the rest off disk.** One process for
	 * the one test file whose shard can no longer be trusted; `BbbTest` is left
	 * alone even though it covers `Hub.php` too, because nothing it was measured
	 * against has moved.
	 *
	 * @return void
	 */
	public function testAWarmRunCapturesOnlyWhatIsDirty(): void {
		$this->coverage()->whole($this->known());
		$this->processes->started = [];

		$this->coverage()->refresh($this->recorded(), $this->digests("/p/src/A.php"));

		self::assertSame(
			[["/usr/bin/php", "vendor/bin/phpunit", "--no-output", "--coverage-php=" . self::PAYLOAD,
				"--log-junit=" . self::JUNIT, "tests/Unit/AaaTest.php"]],
			$this->commands(),
		);
	}

	public function testAWarmRunWithNothingDirtyStartsNoProcess(): void {
		$this->coverage()->whole($this->known());
		$this->processes->started = [];

		$this->coverage()->refresh($this->recorded(), $this->digests());

		self::assertSame([], $this->commands());
	}

	/**
	 * A test file the cache holds no shard for is dirty whatever its digests say:
	 * there is nothing to compare, and nothing to merge either.
	 *
	 * @return void
	 */
	public function testATestFileWithNoShardIsDirty(): void {
		$this->coverage()->whole($this->known());
		$this->filesystem->remove("/p/.mule/cache/tests_Unit_BbbTest.php.json");
		$this->processes->started = [];

		$this->coverage()->refresh($this->recorded(), $this->digests());

		self::assertContains("tests/Unit/BbbTest.php", $this->commands()[0]);
	}

	/**
	 * The merged report carries every shard's attribution, fresh and cached alike —
	 * which is what the engine is handed and the whole reason a warm run can skip
	 * measuring the rest.
	 *
	 * @return void
	 */
	public function testAWarmRunReassemblesEverythingItKnows(): void {
		$this->coverage()->whole($this->known());

		$measured = $this->coverage()->refresh($this->recorded(), $this->digests("/p/src/A.php"));

		self::assertSame(["AaaTest", "BbbTest"], $measured->capture->testClasses());
	}

	public function testAWarmRunRewritesTheShardItReMeasured(): void {
		$this->coverage()->whole($this->known());
		$this->filesystem->files["/p/src/A.php"] = "<?php // a, differently";

		$this->coverage()->refresh($this->recorded(), $this->digests());

		self::assertTrue(
			new Shards($this->filesystem, "/p/.mule/cache")
				->read("tests/Unit/AaaTest.php")
				->matches($this->digests()),
		);
	}

	public function testAWarmRunKeepsWhatTheStoreSaidAboutTheRest(): void {
		$this->coverage()->whole($this->known());

		$measured = $this->coverage()->refresh($this->recorded(), $this->digests("/p/src/A.php"));

		self::assertSame(["src/B.php", "src/Hub.php"], $measured->tests["tests/Unit/BbbTest.php"]->touches);
	}

	/**
	 * **The timings come off disk, never from the partial capture.** A warm run
	 * measures one test file, so its JUnit holds one test — and defaulting the rest
	 * to zero is the one value that can never look slow (M12).
	 *
	 * @return void
	 */
	public function testAWarmRunReadsTheBankedTimings(): void {
		$this->coverage()->whole($this->known());
		$this->filesystem->files[self::JUNIT] = $this->junit(["Aaa"]);

		$measured = $this->coverage()->refresh($this->recorded(), $this->digests("/p/src/A.php"));

		self::assertSame([], $measured->timings->missing(["AaaTest", "BbbTest"]));
	}

	/**
	 * **M16.** A test class that executes no instrumented source is absent from
	 * `testIds()`, so splitting produces nothing for it: a pure enum test, or one
	 * that only asserts about a fixture. It is recorded as running nothing rather
	 * than crashing on a shard that was never made — and the test files after it in
	 * the list are still re-measured.
	 *
	 * @return void
	 */
	public function testWhatComesAfterASilentTestIsStillReMeasured(): void {
		$this->coverage()->whole($this->known());
		$this->processes->started = [];
		$this->silent = ["Aaa"];

		$measured = $this->coverage()->refresh($this->recorded(), $this->digests("/p/src/Hub.php"));

		self::assertSame([], $measured->tests["tests/Unit/AaaTest.php"]->touches);
		self::assertSame(["src/B.php", "src/Hub.php"], $measured->tests["tests/Unit/BbbTest.php"]->touches);
		self::assertCount(2, $this->commands());
	}

	/**
	 * **A warm run needs the reference capture the cold one banked.** Test times
	 * have to come from a single capture (M12, M13), so a cache without them cannot
	 * produce a report at all and the only remedy is a whole run — said here rather
	 * than left to a missing-file message from two layers down.
	 *
	 * @return void
	 */
	public function testAWarmRunWithAnEmptyCache(): void {
		$this->expectExceptionMessage(
			"The cache holds no reference timings, so there is nothing to time the engine's mutants"
			. " against. Test times have to come from one capture rather than from the shards, so the"
			. " remedy is a whole run: mule --whole.",
		);

		$this->coverage()->refresh($this->recorded(), $this->digests());
	}

	/**
	 * And a project where nothing is covered at all has nothing to reassemble: every
	 * test is re-measured, every one attributes nothing, and every shard is
	 * forgotten.
	 *
	 * @return void
	 */
	public function testAWarmRunWhereEveryTestHasStoppedCoveringAnything(): void {
		$this->coverage()->whole($this->known());
		$this->silent = ["Aaa", "Bbb"];

		$this->expectExceptionMessage("The shard cache holds nothing, so there is no coverage to reassemble.");

		$this->coverage()->refresh($this->recorded(), $this->digests("/p/src/Hub.php"));
	}

	/**
	 * **A test that stops covering anything must not leave its old shard behind.**
	 * The digest moves, the shard is correctly read as dirty, the fresh capture
	 * attributes nothing — and then the merge reads the obsolete shard off disk
	 * anyway, so the report mule hands the engine claims coverage from a test that
	 * no longer provides it.
	 *
	 * @return void
	 */
	public function testATestThatStopsCoveringAnythingLeavesNoEvidenceBehind(): void {
		$this->coverage()->whole($this->known());
		$this->silent = ["Aaa"];

		$measured = $this->coverage()->refresh($this->recorded(), $this->digests("/p/tests/Unit/AaaTest.php"));

		self::assertSame(["BbbTest"], $measured->capture->testClasses());
		self::assertFalse($this->filesystem->exists("/p/.mule/cache/tests_Unit_AaaTest.php.json"));
	}

	/**
	 * **Two test classes in one file is refused rather than half-cached.** The
	 * shard cache is keyed by test file and the capture is split by test class, so
	 * the second class would overwrite the first — and the next warm run would
	 * merge only one of them, leaving whatever the other covered looking unreached.
	 *
	 * @return void
	 */
	public function testTwoTestClassesInOneFileIsRefused(): void {
		$this->expectExceptionMessage(
			"tests/Unit/AaaTest.php declares more than one test class — AaaTest and BbbTest. mule"
			. " caches coverage one shard per test file, so a second class in the same file would"
			. " overwrite the first. Give each test class its own file.",
		);

		$this->coverage([
			"AaaTest" => "/p/tests/Unit/AaaTest.php",
			"BbbTest" => "/p/tests/Unit/AaaTest.php",
		])->whole($this->known());
	}

	/**
	 * And refused on the warm path too, because that is where the layout usually
	 * appears: a file that already had one test class acquires a second, so only
	 * its own digest moves and no cold run is triggered to notice.
	 *
	 * @return void
	 */
	public function testASecondTestClassAddedToAnExistingFileIsRefused(): void {
		$this->coverage()->whole($this->known());
		$this->twoInOne = TRUE;

		$this->expectExceptionMessage(
			"tests/Unit/AaaTest.php declares more than one test class — AaaTest and BbbTest. mule"
			. " caches coverage one shard per test file, so a second class in the same file would"
			. " overwrite the first. Give each test class its own file.",
		);

		$this->coverage()->refresh($this->recorded(), $this->digests("/p/tests/Unit/AaaTest.php"));
	}

	/**
	 * **A run that says nothing is indistinguishable from a run that has hung.** A
	 * coverage capture is half a minute on a real project, and for all of it mule is
	 * blocked on a child process — so each wait is announced before it starts, with
	 * how much work it is.
	 *
	 * @return void
	 */
	public function testAColdRunSaysWhatItIsAboutToWaitFor(): void {
		$this->coverage()->whole($this->known());

		self::assertSame(
			["Capturing coverage for 2 test file(s)", "Splitting into 2 shard(s)"],
			$this->progress->stages,
		);
	}

	/**
	 * **Liveness comes from inside the wait.** The only thing that can report mule
	 * is alive while a child runs is the loop reading that child's output, so the
	 * capture hands it somewhere to report to.
	 *
	 * @return void
	 */
	public function testItReportsBeingAliveWhileTheChildRuns(): void {
		$this->coverage()->whole($this->known());

		self::assertGreaterThan(0, $this->progress->ticks);
	}

	/**
	 * A warm run names the file it is re-measuring and counts them off, because
	 * which one is slow is the thing somebody wants to know.
	 *
	 * @return void
	 */
	public function testAWarmRunCountsOffTheFilesItReMeasures(): void {
		$this->coverage()->whole($this->known());
		$this->progress->stages = [];

		$this->coverage()->refresh($this->recorded(), $this->digests("/p/src/Hub.php"));

		self::assertSame(
			[
				"Re-measuring 1 of 2: tests/Unit/AaaTest.php",
				"Re-measuring 2 of 2: tests/Unit/BbbTest.php",
				"Reassembling 2 shard(s)",
			],
			$this->progress->stages,
		);
	}

	public function testAWarmRunWithNothingDirtySaysOnlyWhatItDid(): void {
		$this->coverage()->whole($this->known());
		$this->progress->stages = [];

		$this->coverage()->refresh($this->recorded(), $this->digests());

		self::assertSame(["Reassembling 2 shard(s)"], $this->progress->stages);
	}
}
