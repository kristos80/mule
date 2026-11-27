<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Closure;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\Coverage\Timings;
use Kristos80\Mule\Engine\Declaration;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Commands;
use Kristos80\Mule\Runner\Engine;
use Kristos80\Mule\Runner\Measured;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\RecordingReports;
use Kristos80\Mule\Tests\Support\ScriptedProcesses;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class EngineTest extends TestCase {

	private const string LOG = "/p/.mule/cache/outcome.json";

	private InMemoryFilesystem $filesystem;

	private RecordingReports $reports;

	private ScriptedProcesses $processes;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem();
		$this->reports = new RecordingReports();
		$this->processes = new ScriptedProcesses($this->writes($this->verdicts()));
	}

	/**
	 * A log with one killed mutant against `src/A.php` and no forbidden
	 * statistics.
	 *
	 * @return array<string, mixed>
	 */
	private function verdicts(): array {
		return [
			"stats" => [
				"skippedCount" => 0,
				"timeOutCount" => 0,
				"syntaxErrorCount" => 0,
				"ignoredCount" => 0,
				"killedByStaticAnalysisCount" => 0,
			],
			"killed" => [["mutator" => ["originalFilePath" => "/p/src/A.php"]]],
		];
	}

	/**
	 * An answer that leaves a log behind, exactly as the engine would.
	 *
	 * @param array<string, mixed> $log
	 * @return Closure(list<string>): array{status: int, output: string}
	 */
	private function writes(array $log): Closure {
		return function () use ($log): array {
			$this->filesystem->files[self::LOG] = (string) json_encode($log, JSON_THROW_ON_ERROR);

			return ["status" => 0, "output" => ""];
		};
	}

	/**
	 * @return Measured
	 */
	private function measured(): Measured {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([0 => "AaaTest::testOne"]);
		$coverage->setLineCoverage(["A.php" => [3 => [0 => 1]]]);

		return new Measured(
			Capture::of([
				"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
				"basePath" => "/p/src",
				"codeCoverage" => $coverage,
				"testResults" => [
					"AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.5],
				],
			]),
			Timings::from(
				'<?xml version="1.0"?><testsuites><testsuite name="AaaTest"'
				. ' file="/p/tests/Unit/AaaTest.php" time="1.5"/></testsuites>',
			),
			["tests/Unit/AaaTest.php" => ["src/A.php"]],
		);
	}

	/**
	 * @return Engine
	 */
	private function engine(): Engine {
		$configuration = Configuration::of([]);
		$project = new Project($this->filesystem, "/p");

		return new Engine(
			$this->filesystem,
			$this->processes,
			new Commands($configuration, "/usr/bin/php", ""),
			new Report($this->filesystem, $this->reports),
			new Declaration($configuration, ".mule/cache/outcome.json", ".mule/cache/tmp"),
			$project,
			".mule/cache",
		);
	}

	public function testItReadsTheVerdictsBackOut(): void {
		self::assertSame(1, $this->engine()->over($this->measured(), [])->count("src/A.php", "killed"));
	}

	/**
	 * The log spells a file the way the engine found it; the store spells it
	 * relative to the project, and a verdict filed under the wrong spelling is a
	 * verdict for nothing.
	 *
	 * @return void
	 */
	public function testPathsComeBackInTheStoresSpelling(): void {
		self::assertSame(["src/A.php"], array_keys($this->engine()->over($this->measured(), [])->byFile));
	}

	/**
	 * The report is handed over rather than generated (M1), which is what
	 * `--skip-initial-tests` means and the whole reason there is a cache at all.
	 *
	 * @return void
	 */
	public function testItRendersTheReportTheEngineWillRead(): void {
		$this->engine()->over($this->measured(), []);

		self::assertSame("/p/.mule/cache/coverage/coverage-xml", $this->reports->rendered[0]["target"]);
		self::assertTrue($this->filesystem->exists("/p/.mule/cache/coverage/junit.xml"));
	}

	/**
	 * Infection resolves every path in a configuration relative to that file's own
	 * directory (M11), and there is no command-line flag for the JSON log — so the
	 * file has to exist, inside the project, written by mule.
	 *
	 * @return void
	 */
	public function testItWritesTheConfigurationItself(): void {
		$this->engine()->over($this->measured(), []);

		self::assertStringContainsString(
			'".mule/cache/outcome.json"',
			$this->filesystem->read("/p/mule.infection.json"),
		);
	}

	public function testItRunsFromTheProjectRoot(): void {
		$this->engine()->over($this->measured(), []);

		self::assertSame("/p/", $this->processes->started[0]["directory"]);
	}

	public function testOnlyWhatIsOwedIsMutated(): void {
		$this->engine()->over($this->measured(), ["src/A.php"]);

		self::assertContains("--filter=src/A.php", $this->processes->started[0]["command"]);
	}

	/**
	 * **Last run's log goes first.** Its path is digested into the cache key and so
	 * cannot be made unique per run, which means a run whose engine dies on startup
	 * would otherwise read yesterday's verdicts and bank them against today's keys.
	 *
	 * @return void
	 */
	public function testAStaleLogIsNotReadAsThisRunsAnswer(): void {
		$this->filesystem->files[self::LOG] = (string) json_encode($this->verdicts(), JSON_THROW_ON_ERROR);
		$this->processes = new ScriptedProcesses(
			static fn (): array => ["status" => 1, "output" => "could not start"],
		);

		$this->expectExceptionMessage("The engine wrote no verdicts");

		$this->engine()->over($this->measured(), []);
	}

	public function testItSaysWhatItRanWhenTheEngineWritesNothing(): void {
		$this->processes = new ScriptedProcesses(
			static fn (): array => ["status" => 1, "output" => "Unable to locate a phpunit executable"],
		);

		$this->expectExceptionMessage(
			"The engine wrote no verdicts, so there is nothing to check and nothing was written.\n"
			. "/usr/bin/php vendor/bin/infection --configuration=/p/mule.infection.json"
			. " --coverage=/p/.mule/cache/coverage --skip-initial-tests --with-timeouts --threads=max"
			. " --min-msi=100 --min-covered-msi=100 --no-progress -n\n"
			. "Unable to locate a phpunit executable",
		);

		$this->engine()->over($this->measured(), []);
	}

	/**
	 * **The exit status is not consulted.** The engine exits non-zero for an
	 * escaped mutant, which is a verdict rather than an error — and exits zero in
	 * cases mule refuses to accept (M14). The log is what is read.
	 *
	 * @return void
	 */
	public function testAnEscapedMutantIsAVerdictRatherThanAFailure(): void {
		$log = $this->verdicts();
		$log["escaped"] = [["mutator" => ["originalFilePath" => "/p/src/A.php"]]];
		$this->processes = new ScriptedProcesses(function () use ($log): array {
			$this->filesystem->files[self::LOG] = (string) json_encode($log, JSON_THROW_ON_ERROR);

			return ["status" => 1, "output" => ""];
		});

		self::assertSame(1, $this->engine()->over($this->measured(), [])->count("src/A.php", "escaped"));
	}
}
