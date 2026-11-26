<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Console\Application;
use Kristos80\Mule\Console\Composition;
use Kristos80\Mule\Console\Summary;
use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\RecordingOutput;
use Kristos80\Mule\Tests\Support\RecordingReports;
use Kristos80\Mule\Tests\Support\ScriptedProcesses;
use Kristos80\Mule\Tests\Support\StubClasses;
use Kristos80\Mule\Tests\Support\StubEnvironment;
use Kristos80\Mule\Tests\Support\StubLines;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * The command, over a one-file project.
 *
 * **Through the real composition root.** What is being tested is the thing
 * somebody types, so the wiring is part of it: an `Application` handed a
 * pre-built `Run` would pass while `bin/mule` was broken.
 */
final class ApplicationTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	private RecordingOutput $output;

	/**
	 * Whether the engine should report an escape rather than a kill.
	 */
	private bool $escaping = FALSE;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem(
			files: [
				"/p/composer.lock" => '{"packages":[]}',
				"/p/phpunit.xml" => "<phpunit/>",
				"/p/mule.json" => '{"tests": "tests/Unit"}',
				"/p/src/A.php" => "<?php // a",
				"/p/tests/Unit/AaaTest.php" => "<?php // aaa",
			],
			directories: ["/p/src", "/p/tests/Unit"],
		);
		$this->output = new RecordingOutput();
	}

	/**
	 * @param list<string> $arguments
	 * @return int
	 */
	private function mule(array $arguments = []): int {
		$processes = new ScriptedProcesses($this->answered(...));

		return new Application(
			new Composition(
				$this->filesystem,
				$processes,
				new StubClasses(["AaaTest" => "/p/tests/Unit/AaaTest.php"]),
				new RecordingReports(),
				new StubLines(["/p/src/A.php" => [3]]),
				new StubEnvironment(),
			),
			new Summary(),
			$this->output,
		)($arguments);
	}

	/**
	 * @param list<string> $command
	 * @return array{status: int, output: string}
	 */
	private function answered(array $command): array {
		if(in_array("vendor/bin/phpunit", $command, TRUE)) {
			$coverage = new ProcessedCodeCoverageData();
			$coverage->setTestIds([0 => "AaaTest::testOne"]);
			$coverage->setLineCoverage(["A.php" => [3 => [0 => 1]]]);

			$this->filesystem->payloads["/p/.mule/cache/capture.cov"] = [
				"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
				"basePath" => "/p/src",
				"codeCoverage" => $coverage,
				"testResults" => [
					"AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.5],
				],
			];
			$this->filesystem->files["/p/.mule/cache/junit.xml"] = '<?xml version="1.0"?><testsuites>'
				. '<testsuite name="AaaTest" file="/p/tests/Unit/AaaTest.php" time="1.5"/></testsuites>';
		} else {
			$this->filesystem->files["/p/.mule/cache/outcome.json"] = (string) json_encode([
				"stats" => [
					"skippedCount" => 0,
					"timeOutCount" => 0,
					"syntaxErrorCount" => 0,
					"ignoredCount" => 0,
					"killedByStaticAnalysisCount" => 0,
				],
				$this->escaping ? "escaped" : "killed" => [
					["mutator" => ["originalFilePath" => "/p/src/A.php"]],
				],
			], JSON_THROW_ON_ERROR);
		}

		return ["status" => 0, "output" => ""];
	}

	public function testAProvenProjectExitsZero(): void {
		self::assertSame(0, $this->mule());
	}

	public function testItSaysWhatItDid(): void {
		$this->mule();

		self::assertStringContainsString("Proven: every gated file", $this->output->text());
		self::assertStringContainsString("proven      1 file(s), 1 mutant(s) killed", $this->output->text());
	}

	/**
	 * **1 is "mule ran and something is not proven"; 2 is "mule could not answer
	 * the question".** Collapsing the two is how a pipeline comes to treat a broken
	 * run as a failing gate, and then a fixed gate as a fixed run.
	 *
	 * @return void
	 */
	public function testAnUnprovenProjectExitsOne(): void {
		$this->escaping = TRUE;

		self::assertSame(1, $this->mule());
		self::assertStringContainsString("NOT PROVEN: 1 file(s)", $this->output->text());
	}

	public function testSomethingMuleCannotAnswerExitsTwo(): void {
		unset($this->filesystem->files["/p/composer.lock"]);

		self::assertSame(2, $this->mule());
		self::assertStringContainsString("mule needs composer.lock", $this->output->text());
	}

	/**
	 * **The message, and nothing underneath it.** Every `Failure` mule throws is
	 * written for the person reading it, and a stack trace above the sentence that
	 * explains the problem is how somebody comes to read the trace instead.
	 *
	 * @return void
	 */
	public function testAFailureIsOneSentenceRatherThanATrace(): void {
		unset($this->filesystem->files["/p/composer.lock"]);
		$this->mule();

		self::assertCount(1, $this->output->lines);
	}

	public function testAskingForEverythingAnyway(): void {
		$this->mule();

		self::assertSame(0, $this->mule(["--whole"]));
		self::assertStringContainsString("1 file(s) handed to the engine.", $this->output->text());
	}

	public function testASecondRunSaysThereWasNothingToDo(): void {
		$this->mule();
		$this->output = new RecordingOutput();

		self::assertSame(0, $this->mule());
		self::assertStringContainsString("Nothing is owed", $this->output->text());
	}

	public function testTheHelp(): void {
		self::assertSame(0, $this->mule(["--help"]));
		self::assertStringContainsString("mutate only the files whose score", $this->output->text());
		self::assertStringContainsString("mule --whole", $this->output->text());
	}

	public function testTheShortHelp(): void {
		self::assertSame(0, $this->mule(["-h"]));
		self::assertStringContainsString("Exit codes:", $this->output->text());
	}

	public function testHelpMeasuresNothing(): void {
		$this->mule(["--help"]);

		self::assertFalse($this->filesystem->exists("/p/.mule/verified.json"));
	}

	public function testSomethingMuleDoesNotKnow(): void {
		self::assertSame(2, $this->mule(["--fast", "--quiet"]));
		self::assertSame("mule does not know --fast --quiet. Try --help.", $this->output->text());
	}

	public function testAnUnknownArgumentMeasuresNothing(): void {
		$this->mule(["--fast"]);

		self::assertFalse($this->filesystem->exists("/p/.mule/verified.json"));
	}

	/**
	 * **A verdict is reusable only under an equivalent verification environment**,
	 * so every part of the toolchain has to reach the key — and the only way to see
	 * that from outside is that a changed one makes the next run cold.
	 *
	 * @return void
	 */
	public function testUpgradingADependencyThrowsTheStoreAway(): void {
		$this->mule();
		$this->filesystem->files["/p/composer.lock"] = '{"packages":[{"name":"phpunit/phpunit"}]}';
		$this->output = new RecordingOutput();

		$this->mule();

		self::assertStringContainsString("verification environment has changed", $this->output->text());
	}

	/**
	 * The PHPUnit configuration decides what is instrumented, and therefore what a
	 * coverage report can say at all.
	 *
	 * @return void
	 */
	public function testChangingWhatIsInstrumentedThrowsTheStoreAway(): void {
		$this->mule();
		$this->filesystem->files["/p/phpunit.xml"] = "<phpunit><!-- one more exclusion --></phpunit>";
		$this->output = new RecordingOutput();

		$this->mule();

		self::assertStringContainsString("verification environment has changed", $this->output->text());
	}

	/**
	 * The mutator set decides which mutants exist at all, so the same tests under a
	 * wider gate are not the same claim.
	 *
	 * @return void
	 */
	public function testChangingTheGateThrowsTheStoreAway(): void {
		$this->mule();
		// **A different gate of the same size**, so what invalidates the store is
		// the mutators' names and not merely how many there are.
		$this->filesystem->files["/p/mule.json"] = '{"tests": "tests/Unit", "mutators": {"@cast": true}}';
		$this->output = new RecordingOutput();

		$this->mule();

		self::assertStringContainsString("verification environment has changed", $this->output->text());
	}

	public function testRenamingNothingLeavesTheStoreAlone(): void {
		$this->mule();
		$this->output = new RecordingOutput();

		$this->mule();

		self::assertStringContainsString("Nothing is owed", $this->output->text());
	}

	/**
	 * Infection resolves every path in a configuration relative to that file's own
	 * directory (M11), so the declaration goes at the project root — and every path
	 * in it is project-relative, because it is digested into the key and an absolute
	 * one is a key no other machine can reproduce.
	 *
	 * @return void
	 */
	public function testWhatItHandsTheEngine(): void {
		$this->mule();

		self::assertSame(
			'{
    "source": {
        "directories": [
            "src"
        ]
    },
    "mutators": {
        "@default": true
    },
    "logs": {
        "json": ".mule/cache/outcome.json"
    },
    "testFramework": "phpunit",
    "tmpDir": ".mule/cache/tmp"
}
',
			$this->filesystem->read("/p/mule.infection.json"),
		);
	}
}
