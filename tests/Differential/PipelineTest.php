<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Differential;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Merge;
use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\Coverage\Shard;
use Kristos80\Mule\Coverage\Split;
use Kristos80\Mule\Coverage\Timings;
use Kristos80\Mule\External\PhpCoverageReport;
use Kristos80\Mule\External\PhpFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * The one test that would have caught M13.
 *
 * **Two runs over one project state, compared at mutant identity.** The first is
 * what Infection gets without mule: PHPUnit's own `--coverage-xml` and
 * `--log-junit`, handed straight over. The second is mule's whole pipeline —
 * capture, split, thin to disk, read back, merge, synthesise the timings, render
 * — and then the same engine over that.
 *
 * They must agree on **which mutants exist** and **what happened to each**.
 *
 * Comparing aggregates is not enough, and that is the whole point: when merging
 * shard JUnits silently skipped 40 of 1,174 mutants on a real project, both runs
 * still reported exactly 1,174 and MSI moved 95.32 → 95.15 — a difference that
 * reads as noise. The defect was only ever visible here, in the killed/skipped
 * split per mutant.
 *
 * This suite needs a coverage driver and does not pretend otherwise. It fails
 * with a message rather than skipping, because a differential that quietly does
 * not run is worse than one that is red.
 */
final class PipelineTest extends TestCase {

	private const string FIXTURE = __DIR__ . "/../Fixture/project";

	private string $work;

	protected function setUp(): void {
		self::assertTrue(
			$this->hasCoverageDriver(),
			"The differential needs a coverage driver: run it as `composer test:differential`.",
		);

		$this->work = sys_get_temp_dir() . "/mule-differential-" . bin2hex(random_bytes(8));

		mkdir($this->work, 0o700);
	}

	protected function tearDown(): void {
		exec("rm -rf " . escapeshellarg($this->work));
	}

	/**
	 * @return void
	 */
	public function testTheCachePipelineReproducesInfectionsOwnVerdicts(): void {
		$this->capture();

		$native = $this->mutate($this->at("native"), $this->at("native.json"));
		$this->buildThroughTheCache();
		$cached = $this->mutate($this->at("cached"), $this->at("cached.json"));

		self::assertNotSame([], $native, "The baseline produced no mutants, so there is nothing to compare.");

		// identities first: a mutant that exists in one run and not the other is
		// the failure M13 was, and it is invisible in any count
		self::assertSame(
			array_keys($native),
			array_keys($cached),
			"The two runs disagree about which mutants exist.",
		);

		// then verdicts, which is where a silently skipped mutant shows up
		self::assertSame($native, $cached, "The two runs disagree about what happened to a mutant.");
	}

	/**
	 * The fixture is deliberately under-tested, so a run that reports everything
	 * killed is a run that lost the survivor — and a differential of two
	 * all-killed runs would agree about nothing worth agreeing on.
	 *
	 * @return void
	 */
	public function testTheComparisonIncludesAVerdictThatIsNotKilled(): void {
		$this->capture();

		self::assertContains("escaped", $this->mutate($this->at("native"), $this->at("native.json")));
	}

	/**
	 * G2: a skipped mutant is counted in no detail array and does not lower MSI,
	 * so the only way to see one is to ask.
	 *
	 * @return void
	 */
	public function testNeitherRunSkipsAMutant(): void {
		$this->capture();

		$this->mutate($this->at("native"), $this->at("native.json"));
		self::assertSame(0, $this->statistic($this->at("native.json"), "skippedCount"));

		$this->buildThroughTheCache();
		$this->mutate($this->at("cached"), $this->at("cached.json"));
		self::assertSame(0, $this->statistic($this->at("cached.json"), "skippedCount"));
	}

	/**
	 * **Proof that the comparison has teeth.**
	 *
	 * A differential that passes is only worth having if it would fail. So the
	 * defect it exists for is put back: inflated test times, which is what
	 * concatenating shard JUnits produces (M13). Infection then discards the
	 * mutants whose covering tests look too slow, counts them in no detail array,
	 * and leaves MSI almost where it was.
	 *
	 * The inflation here is arithmetic rather than a second capture, because the
	 * cause is the number and not how it was arrived at.
	 *
	 * @return void
	 */
	public function testTheComparisonFailsWhenTheTimingsAreInflated(): void {
		$this->capture();

		$native = $this->mutate($this->at("native"), $this->at("native.json"));

		$this->buildThroughTheCache(static fn(Timings $timings): Timings => Timings::of(array_map(
			static fn(array $timing): array => ["file" => $timing["file"], "time" => $timing["time"] * 200],
			$timings->classes,
		)));

		$distorted = $this->mutate($this->at("cached"), $this->at("cached.json"));

		// the aggregate barely moves, which is exactly why it was missed
		self::assertGreaterThan(0, $this->statistic($this->at("cached.json"), "skippedCount"));

		// but the identities do not match, and that the comparison can see
		self::assertNotSame(
			array_keys($native),
			array_keys($distorted),
			"Inflated timings cost mutants, and the differential has to notice.",
		);
	}

	/**
	 * One PHPUnit run, three artifacts: the XML report Infection would normally
	 * be given, the JUnit log beside it, and the `.cov` payload mule's pipeline
	 * starts from. One execution, so the two runs cannot differ because the tests
	 * ran twice.
	 *
	 * @return void
	 */
	private function capture(): void {
		mkdir($this->at("native"), 0o700, TRUE);

		$command = sprintf(
			"cd %s && XDEBUG_MODE=coverage %s --no-output --coverage-php=%s --coverage-xml=%s --log-junit=%s"
			. " --exclude-source-from-xml-coverage 2>&1",
			escapeshellarg(self::FIXTURE),
			self::interpreter() . " " . escapeshellarg(self::binary("phpunit")),
			escapeshellarg($this->at("mono.cov")),
			escapeshellarg($this->at("native/coverage-xml")),
			escapeshellarg($this->at("native/junit.xml")),
		);

		exec($command, $output, $status);

		self::assertSame(0, $status, "The fixture's suite did not pass:\n" . implode("\n", $output));
		self::assertFileExists($this->at("native/coverage-xml/index.xml"));
	}

	/**
	 * mule's pipeline, every step of it, with the shards going to disk and coming
	 * back rather than being kept in hand.
	 *
	 * @param null|callable(Timings): Timings $distort
	 * @return void
	 */
	private function buildThroughTheCache(?callable $distort = NULL): void {
		$filesystem = new PhpFilesystem();
		$monolithic = Capture::of(require $this->at("mono.cov"));

		$cache = $this->at("shards");
		$filesystem->createDirectory($cache);

		foreach(new Split($monolithic)->byTestClass() as $class => $shard) {
			$filesystem->write(
				"{$cache}/" . str_replace("\\", "_", $class) . ".json",
				json_encode(Shard::of($class, $shard, [])->toArray(), JSON_THROW_ON_ERROR),
			);
		}

		$captures = [];

		foreach((array) glob("{$cache}/*.json") as $file) {
			$captures[] = Shard::fromArray(
				json_decode($filesystem->read((string) $file), TRUE, 512, JSON_THROW_ON_ERROR),
			)->capture($monolithic, $monolithic->basePath);
		}

		$timings = Timings::from($filesystem->read($this->at("native/junit.xml")));

		new Report($filesystem, new PhpCoverageReport())->write(
			new Merge($monolithic, $captures)->into($monolithic->basePath),
			$distort === NULL ? $timings : $distort($timings),
			$this->at("cached"),
		);
	}

	/**
	 * Runs the engine over a report directory and returns what it decided, keyed
	 * by mutant identity.
	 *
	 * @param string $coverage
	 * @param string $log
	 * @return array<string, string> Identity to verdict.
	 */
	private function mutate(string $coverage, string $log): array {
		$this->configure($log);

		$command = sprintf(
			"cd %s && %s --coverage=%s --skip-initial-tests --with-timeouts --threads=4"
			. " --no-progress -n 2>&1",
			escapeshellarg(self::FIXTURE),
			self::interpreter() . " " . escapeshellarg(self::binary("infection")),
			escapeshellarg($coverage),
		);

		exec($command, $output, $status);

		self::assertSame(0, $status, "The engine failed over {$coverage}:\n" . implode("\n", $output));

		return $this->verdicts($log);
	}

	/**
	 * The JSON log, reduced to identity and verdict.
	 *
	 * Identity is `(mutator, file, line, hash of the mutated source)` — enough to
	 * tell two mutants apart on the same line, which `originalStartLine` alone
	 * cannot. The mutant ID Infection prints is not in the log (M6), so this is
	 * derived rather than read.
	 *
	 * @param string $log
	 * @return array<string, string>
	 */
	private function verdicts(string $log): array {
		$decoded = json_decode((string) file_get_contents($log), TRUE, 512, JSON_THROW_ON_ERROR);

		self::assertIsArray($decoded);

		$verdicts = [];

		foreach(["killed", "escaped", "errored", "timeouted", "syntaxErrors", "uncovered", "ignored"] as $verdict) {
			foreach((array) ($decoded[$verdict] ?? []) as $mutant) {
				$verdicts[$this->identity((array) $mutant)] = $verdict;
			}
		}

		ksort($verdicts);

		return $verdicts;
	}

	/**
	 * @param array<array-key, mixed> $mutant
	 * @return string
	 */
	private function identity(array $mutant): string {
		$mutator = $mutant["mutator"] ?? NULL;

		self::assertIsArray($mutator, "A logged mutant carries no mutator.");

		$name = $mutator["mutatorName"] ?? NULL;
		$file = $mutator["originalFilePath"] ?? NULL;
		$line = $mutator["originalStartLine"] ?? NULL;
		$mutated = $mutator["mutatedSourceCode"] ?? NULL;

		self::assertIsString($name);
		self::assertIsString($file);
		self::assertIsInt($line);
		self::assertIsString($mutated);

		return sprintf("%s@%s:%d#%s", $name, basename($file), $line, md5($mutated));
	}

	/**
	 * @param string $log
	 * @param string $name
	 * @return int
	 */
	private function statistic(string $log, string $name): int {
		$decoded = json_decode((string) file_get_contents($log), TRUE, 512, JSON_THROW_ON_ERROR);

		self::assertIsArray($decoded);

		$stats = $decoded["stats"] ?? NULL;

		self::assertIsArray($stats, "The engine's log carries no statistics.");

		$value = $stats[$name] ?? NULL;

		self::assertIsInt($value, "The engine's log gives no {$name}.");

		return $value;
	}

	/**
	 * Renders the engine's configuration into the fixture, per run.
	 *
	 * **It has to live in the project**, because Infection resolves every path
	 * in it — `source`, `phpUnit.customPath` — relative to the configuration's
	 * own directory rather than to the working directory (M11). There is no CLI
	 * flag for the JSON log either, so varying where the verdicts land means
	 * rendering the file. Which is what mule does in earnest, and why the
	 * configuration is in the cache key (§4) rather than written by a consumer.
	 *
	 * @param string $log Where this run's verdicts go.
	 * @return void
	 */
	private function configure(string $log): void {
		file_put_contents(self::FIXTURE . "/infection.json5", (string) json_encode([
			"source" => ["directories" => ["src"]],
			"mutators" => ["@default" => TRUE],
			"logs" => ["json" => $log],
			"testFramework" => "phpunit",
			"tmpDir" => $this->at("itmp"),
			// Infection finds phpunit by putting `composer config bin-dir`'s
			// *relative* answer on PATH, which fails from a directory with no
			// vendor/ of its own. Naming it outright has no finder in it.
			"phpUnit" => ["customPath" => self::binary("phpunit")],
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}

	/**
	 * The interpreter to start children with, carrying the parent's ini.
	 *
	 * **A child does not inherit a driver that arrived on the command line.**
	 * `XDEBUG_MODE=coverage` in the environment is not enough — the extension has
	 * to be *loaded* — so a plain `PHP_BINARY` child writes a JUnit log, no
	 * coverage at all, and exits 1 with nothing on stdout because `--no-output`
	 * swallowed the reason.
	 *
	 * Forwarding `-c` fixes it in both places this runs: locally the parent's
	 * loaded ini is the one that pulls in Xdebug, and in CI it is the php.ini
	 * that already has pcov, where forwarding it changes nothing.
	 *
	 * @return string
	 */
	private static function interpreter(): string {
		$ini = php_ini_loaded_file();

		return escapeshellarg(PHP_BINARY) . ($ini === FALSE ? "" : " -c " . escapeshellarg($ini));
	}

	/**
	 * **The interpreter and the script are escaped separately.** Quoting them as
	 * one string hands the shell a single command name containing a space, and
	 * what comes back is a bare 127 with the whole command reported as missing.
	 *
	 * @param string $name
	 * @return string
	 */
	private static function binary(string $name): string {
		return dirname(__DIR__, 2) . "/vendor/bin/{$name}";
	}

	/**
	 * @param string $relative
	 * @return string
	 */
	private function at(string $relative): string {
		return "{$this->work}/{$relative}";
	}

	/**
	 * @return bool
	 */
	private function hasCoverageDriver(): bool {
		return extension_loaded("pcov")
			|| (extension_loaded("xdebug") && str_contains((string) getenv("XDEBUG_MODE"), "coverage"));
	}
}
