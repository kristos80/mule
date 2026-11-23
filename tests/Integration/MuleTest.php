<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `bin/mule` itself, run as a program against a project on disk.
 *
 * Everything the unit suite replaces with an array is real here: the
 * filesystem, two child processes, and the project's own Composer autoloader
 * resolving the class a coverage report names into the file whose digest is
 * half of the cache key. What stands in for the two slow programs is a coverage
 * run that writes a report and an engine that exits clean and records that it
 * was asked — which is the one thing a cache must be watched for: whether the
 * second run asks at all.
 */
#[CoversNothing]
final class MuleTest extends TestCase {

	use TemporaryTree;

	private const string SOURCE = "<?php\n\nnamespace Fixture;\n\nfinal class A {}\n";

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->createTree();
		$this->givenAProject();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->removeTree();
	}

	public function testMutatesTheProjectAndBanksWhatItProved(): void {
		$run = $this->mule("full");

		self::assertSame(0, $run["status"], $run["output"]);
		self::assertStringContainsString("mule: 1 file(s) recorded at the full score in .mule/verified.json.", $run["output"]);
		self::assertSame(1, $this->engineRuns());
	}

	public function testRecordsTheDigestsOfTheSourceAndOfTheTestCoveringIt(): void {
		$this->mule("full");

		self::assertSame(
			"{\n\t\"src/A.php\": {\"source\":\"" . hash("sha256", self::SOURCE) . "\",\"tests\":\""
				. hash("sha256", "tests/ATest.php:" . hash_file("sha256", $this->path("tests/ATest.php"))) . "\"}\n}\n",
			file_get_contents($this->path(".mule/verified.json")),
		);
	}

	public function testAsksTheEngineForNothingWhenTheStoreVouchesForEveryFile(): void {
		$this->mule("full");

		$run = $this->mule("new");

		self::assertSame(0, $run["status"], $run["output"]);
		self::assertStringContainsString("mule: 1 of 1 files verified, 0 stale.", $run["output"]);
		self::assertStringContainsString("nothing to mutate", $run["output"]);
		self::assertSame(1, $this->engineRuns());
	}

	public function testAsksTheEngineAgainForAFileWhoseSourceChanged(): void {
		$this->mule("full");
		$this->write("src/A.php", self::SOURCE . "\n// changed\n");

		$run = $this->mule("new");

		self::assertSame(0, $run["status"], $run["output"]);
		self::assertStringContainsString("mule: 0 of 1 files verified, 1 stale.", $run["output"]);
		self::assertStringContainsString("--filter=src/A.php", $this->engineArguments());
	}

	public function testAsksTheEngineAgainForAFileWhoseCoveringTestChanged(): void {
		$this->mule("full");
		$this->write("tests/ATest.php", "<?php\n\nnamespace Fixture\\Tests;\n\nfinal class ATest { /* weakened */ }\n");

		$run = $this->mule("new");

		self::assertStringContainsString("mule: 0 of 1 files verified, 1 stale.", $run["output"]);
		self::assertStringContainsString("--filter=src/A.php", $this->engineArguments());
	}

	public function testReportsWhatTheStoreClaimsWithoutAskingTheEngine(): void {
		$this->mule("full");

		$run = $this->mule("status");

		self::assertSame(0, $run["status"], $run["output"]);
		self::assertStringContainsString("mule: 1 of 1 files verified, 0 stale.", $run["output"]);
		self::assertSame(1, $this->engineRuns());
	}

	public function testLeavesNoScratchDirectoryBehind(): void {
		$before = glob(sys_get_temp_dir() . "/mule.*") ?: [];

		$this->mule("full");

		self::assertSame($before, glob(sys_get_temp_dir() . "/mule.*") ?: []);
	}

	public function testRefusesAVerbItDoesNotHave(): void {
		$run = $this->mule("diff");

		self::assertSame(1, $run["status"]);
		self::assertStringContainsString("mule: Unknown verb diff. usage: mule new|full|status", $run["output"]);
	}

	/**
	 * Runs mule in the fixture project.
	 *
	 * @param string $verb
	 * @return array{status: int, output: string}
	 */
	private function mule(string $verb): array {
		$mule = realpath(__DIR__ . "/../../bin/mule");
		$command = sprintf(
			"cd %s && %s %s %s 2>&1",
			escapeshellarg($this->path()),
			escapeshellarg(PHP_BINARY),
			escapeshellarg((string) $mule),
			escapeshellarg($verb),
		);
		$lines = [];
		$status = 1;

		exec($command, $lines, $status);

		return ["status" => $status, "output" => implode("\n", $lines)];
	}

	/**
	 * How many times the engine was asked to mutate anything.
	 *
	 * @return int
	 */
	private function engineRuns(): int {
		return count(file_exists($this->path("engine.log")) ? file($this->path("engine.log")) ?: [] : []);
	}

	/**
	 * What the engine was asked, on its last run.
	 *
	 * @return string
	 */
	private function engineArguments(): string {
		return file_exists($this->path("engine.log")) ? (string) file_get_contents($this->path("engine.log")) : "";
	}

	/**
	 * A project with one source file, one test covering it, a coverage run that
	 * writes a report where it is told to, and an engine that exits clean.
	 *
	 * @return void
	 */
	private function givenAProject(): void {
		$this->write("composer.json", json_encode([
			"autoload" => ["psr-4" => ["Fixture\\" => "src"]],
			"autoload-dev" => ["psr-4" => ["Fixture\\Tests\\" => "tests"]],
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
		$this->write("src/A.php", self::SOURCE);
		$this->write("tests/ATest.php", "<?php\n\nnamespace Fixture\\Tests;\n\nfinal class ATest {}\n");
		$this->write("vendor/autoload.php", $this->autoloader());
		$this->write("bin/coverage.php", $this->coverageRun());
		$this->write("bin/engine", "#!/bin/sh\necho \"\$@\" >> \"\$(dirname \"\$0\")/../engine.log\"\nexit 0\n", 0o700);
		$this->write("mule.json", json_encode([
			"coverage" => [
				PHP_BINARY,
				$this->path("bin/coverage.php"),
				"--coverage-xml={coverage-xml}",
				"--log-junit={junit}",
			],
			"binary" => $this->path("bin/engine"),
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}

	/**
	 * The project's autoloader, which is all Composer's own is: an object that
	 * answers where a class lives, returned to whoever required the file.
	 *
	 * @return string
	 */
	private function autoloader(): string {
		$classLoader = realpath(__DIR__ . "/../../vendor/composer/ClassLoader.php");

		// Guarded exactly as Composer's own autoload_real.php guards it: this
		// machine's PHP prepends a phar that has already declared the class, so
		// requiring a second copy of the file would be fatal.
		return <<<PHP
			<?php
			if(!class_exists(\\Composer\\Autoload\\ClassLoader::class, FALSE)) {
				require_once "{$classLoader}";
			}

			\$loader = new \\Composer\\Autoload\\ClassLoader();

			\$loader->addPsr4("Fixture\\\\Tests\\\\", realpath(__DIR__ . "/../tests"));
			\$loader->addPsr4("Fixture\\\\", realpath(__DIR__ . "/../src"));
			\$loader->register();

			return \$loader;
			PHP;
	}

	/**
	 * A stand-in for the coverage run: it writes the report PHPUnit would
	 * write, where mule told it to, naming the one test that covers the one
	 * source file.
	 *
	 * @return string
	 */
	private function coverageRun(): string {
		return <<<'PHP'
			<?php
			$coverage = "";
			$junit = "";

			foreach($argv as $argument) {
				if(str_starts_with($argument, "--coverage-xml=")) {
					$coverage = substr($argument, strlen("--coverage-xml="));
				}

				if(str_starts_with($argument, "--log-junit=")) {
					$junit = substr($argument, strlen("--log-junit="));
				}
			}

			mkdir($coverage, 0700, TRUE);

			$source = realpath(__DIR__ . "/../src");

			file_put_contents("{$coverage}/index.xml", <<<XML
				<?xml version="1.0"?>
				<phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
					<project source="{$source}"/>
				</phpunit>
				XML);
			file_put_contents("{$coverage}/A.php.xml", <<<XML
				<?xml version="1.0"?>
				<phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
					<file name="A.php" path="/">
						<coverage>
							<line nr="5"><covered by="Fixture\Tests\ATest::testOne"/></line>
						</coverage>
					</file>
				</phpunit>
				XML);
			file_put_contents($junit, "<testsuites/>");
			PHP;
	}
}
