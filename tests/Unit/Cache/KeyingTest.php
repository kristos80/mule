<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Declared;
use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Reading;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Universe;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\CoverageXml;
use Kristos80\Mule\Tests\Support\CoveringProcesses;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\MappedClasses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeyingTest extends TestCase {

	private const string COMPOSER = '{"autoload": {"psr-4": {"Acme\\\\": "src"}}}';

	private const string SOURCE = "<?php class Key {}";

	private const string KEY_TEST = "<?php class KeyTest {}";

	private const string GATE_TEST = "<?php class GateTest {}";

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", self::COMPOSER)
			->given("/project/src/Cache/Key.php", self::SOURCE)
			->given("/project/tests/KeyTest.php", self::KEY_TEST)
			->given("/project/tests/GateTest.php", self::GATE_TEST)
			->given("/run/coverage-xml/index.xml", CoverageXml::index("/project/src"));
	}

	public function testKeysACoveredFileAgainstItsOwnDigest(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(hash("sha256", self::SOURCE), $this->verifiedDigestOf("src/Cache/Key.php"));
	}

	public function testRecordsEachCoveringTestWithItsOwnDigest(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(hash("sha256", self::KEY_TEST), $this->testEntry("tests/KeyTest.php")["source"]);
	}

	public function testRecordsWhatEachTestWasSeenToRun(): void {
		$this->filesystem->given("/project/src/Project.php", "<?php class Project {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\GateTest::testOne"]);
		$this->givenReport("Project.php", "Project.php", "/", ["Acme\\Tests\\GateTest::testTwo"]);

		self::assertSame(
			["src/Cache/Key.php", "src/Project.php"],
			$this->testEntry("tests/GateTest.php")["touches"],
		);
	}

	public function testNamesEachFileATestRunsOnceHoweverManyOfItsMethodsRunIt(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", [
			"Acme\\Tests\\KeyTest::testOne",
			"Acme\\Tests\\KeyTest::testTwo",
		]);

		self::assertSame(["src/Cache/Key.php"], $this->testEntry("tests/KeyTest.php")["touches"]);
	}

	/**
	 * The report is read in report-path order, so the two are decoupled here on
	 * purpose: what a test runs is recorded sorted by *source* path, not in the
	 * order the files happened to be read.
	 *
	 * @return void
	 */
	public function testSortsWhatATestRunsSoTheReportsOwnOrderCannotChangeTheStore(): void {
		$this->filesystem->given("/project/src/Aaa.php", "<?php class Aaa {}");
		$this->filesystem->given("/project/src/Zzz.php", "<?php class Zzz {}");
		// The report named aaa is the one about Zzz.php, so the files are read
		// in the opposite order to the one they are recorded in.
		$this->givenReport("aaa.php", "Zzz.php", "/", ["Acme\\Tests\\GateTest::testOne"]);
		$this->givenReport("zzz.php", "Aaa.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		self::assertSame(["src/Aaa.php", "src/Zzz.php"], $this->testEntry("tests/GateTest.php")["touches"]);
	}

	public function testRecordsEveryTestRatherThanTheFirst(): void {
		$this->filesystem->given("/project/src/Project.php", "<?php class Project {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);
		$this->givenReport("Project.php", "Project.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		self::assertSame(["tests/GateTest.php", "tests/KeyTest.php"], array_keys($this->decoded()["tests"]));
	}

	public function testRecordsEveryTestThatRunsOneFileRatherThanTheFirst(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", [
			"Acme\\Tests\\KeyTest::testOne",
			"Acme\\Tests\\GateTest::testOne",
		]);

		$tests = $this->decoded()["tests"];

		self::assertSame(["tests/GateTest.php", "tests/KeyTest.php"], array_keys($tests));
	}

	public function testRecordsNoTestForAFileNothingCovers(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", []);

		self::assertSame([], $this->decoded()["tests"]);
	}

	public function testKeysEveryFileTheReportHolds(): void {
		$this->filesystem->given("/project/src/Project.php", "<?php class Project {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);
		$this->givenReport("Project.php", "Project.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		self::assertSame(["src/Cache/Key.php", "src/Project.php"], $this->reading()->snapshot->paths());
	}

	public function testKeysNothingFromAReportHoldingNoFiles(): void {
		self::assertSame(0, $this->reading()->count());
	}

	public function testReadsACoveringTestNamedWithoutAMethod(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest"]);

		self::assertSame(["src/Cache/Key.php"], $this->testEntry("tests/KeyTest.php")["touches"]);
	}

	public function testKeepsAFileTheTestsDoNotReachAllOfOutOfTheVerdicts(): void {
		$this->givenPartialReport();

		$reading = $this->reading();

		self::assertSame([], $reading->snapshot->paths());
		self::assertSame(["src/Cache/Key.php"], $reading->unproven());
		self::assertSame("one() lines 12-19, 3 unreached", $reading->gaps["src/Cache/Key.php"][0]->described());
	}

	public function testStillRecordsWhatTheTestsOfAnUnprovenFileRun(): void {
		$this->givenPartialReport();

		self::assertSame(["src/Cache/Key.php"], $this->testEntry("tests/KeyTest.php")["touches"]);
	}

	public function testCarriesOnPastAFileItCannotProve(): void {
		$this->filesystem->given("/project/src/Project.php", "<?php class Project {}");
		$this->givenPartialReport();
		$this->givenReport("Project.php", "Project.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		$reading = $this->reading();

		self::assertSame(["src/Cache/Key.php"], $reading->unproven());
		self::assertSame(["src/Project.php"], $reading->snapshot->paths());
	}

	public function testStillCountsAFileItCannotProve(): void {
		$this->givenPartialReport();

		self::assertSame(1, $this->reading()->count());
	}

	public function testWatchesEveryAutoloadedFileTheReportNeverMentioned(): void {
		$this->filesystem->given("/project/src/Excluded.php", "<?php class Excluded {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		// Exactly these: every autoloaded file the report never mentioned — a
		// file excluded from coverage, and a test that ran nothing. Not the
		// proven file, not its test, and not what the project declares, which
		// is recorded apart from this.
		self::assertSame(
			["src/Excluded.php", "tests/GateTest.php"],
			array_keys($this->decoded()["watched"]),
		);
	}

	/**
	 * The engine's own configuration file is not read by anything any more:
	 * mule renders what the engine needs per run. Hashing a file that changes
	 * nothing would unprove verdicts for no reason.
	 *
	 * @return void
	 */
	/**
	 * Which mutants exist is the engine's business, so the version that would
	 * prove a verdict is recorded beside it.
	 *
	 * @return void
	 */
	public function testRecordsTheEngineThatWouldProveTheVerdicts(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(
			["infection/infection" => ["version" => "0.32.7"]],
			$this->decoded()["engine"],
		);
	}

	public function testRecordsNoEngineFromAPartialReport(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame([], $this->reading(["src/Cache/Key.php"])->snapshot->engine());
	}

	public function testIgnoresAnEngineConfigurationTheProjectStillHasLyingAround(): void {
		$this->filesystem->given("/project/infection.json5", '{"mutators": {"@default": true}}');
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(["composer.json"], array_keys($this->decoded()["configuration"]));
	}

	public function testRecordsItsOwnConfigurationBecauseEveryKeyInItDefinesTheGate(): void {
		$this->filesystem->given("/project/mule.json", '{"mutators": {"default": true}}');
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(
			["composer.json", "mule.json"],
			array_keys($this->decoded()["configuration"]),
		);
		self::assertArrayNotHasKey("mule.json", $this->decoded()["watched"]);
	}

	public function testRecordsWhereTheTestsStopAgainstTheBytesItMeasured(): void {
		$this->givenPartialReport();

		$unproven = $this->decoded()["unproven"];

		self::assertArrayHasKey("src/Cache/Key.php", $unproven);
		self::assertSame([
			"digest" => hash("sha256", self::SOURCE),
			"unreached" => [["name" => "one", "from" => 12, "to" => 19, "lines" => 3]],
		], $unproven["src/Cache/Key.php"]);
	}

	public function testRecordsEveryGapRatherThanTheFirst(): void {
		$this->filesystem->given("/project/src/Second.php", "<?php class Second {}");
		$this->givenPartialReport();
		$this->filesystem->given("/run/coverage-xml/Second.php.xml", CoverageXml::partialFile(
			"Second.php",
			"/",
			[],
			2,
			0,
			[["name" => "two", "start" => 3, "end" => 6, "executable" => 2, "executed" => 0]],
		));

		$unproven = $this->decoded()["unproven"];

		self::assertSame(["src/Cache/Key.php", "src/Second.php"], array_keys($unproven));
	}

	public function testDoesNotAlsoWatchAFileItCouldNotProve(): void {
		$this->givenPartialReport();

		self::assertArrayNotHasKey("src/Cache/Key.php", $this->decoded()["watched"]);
	}

	public function testReadsOnlyThePathsAPartialReportIsAboutAtAll(): void {
		$this->filesystem->given("/project/src/Project.php", "<?php class Project {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);
		$this->givenReport("Project.php", "Project.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		$reading = $this->reading(["src/Cache/Key.php"]);

		self::assertSame(["src/Cache/Key.php"], $reading->snapshot->paths());
		self::assertSame(["tests/KeyTest.php"], array_keys($reading->snapshot->tests()));
	}

	public function testRecordsHowMuchOfAProvenFileTheTestsReached(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(
			["src/Cache/Key.php" => ["executable" => 1, "executed" => 1]],
			$this->decoded()["coverage"],
		);
	}

	public function testRecordsHowMuchOfAFileItCouldNotProveTheTestsReached(): void {
		$this->givenPartialReport();

		self::assertSame(
			["src/Cache/Key.php" => ["executable" => 4, "executed" => 1]],
			$this->decoded()["coverage"],
		);
	}

	/**
	 * How much of one file ran is a fact about that file, measured from its own
	 * report — so unlike what is watched, a subset run holds the truth about
	 * every path it was given.
	 *
	 * @return void
	 */
	public function testStillRecordsCoverageFromAPartialReport(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(
			["src/Cache/Key.php"],
			array_keys($this->reading(["src/Cache/Key.php"])->snapshot->coverage()),
		);
	}

	public function testWatchesNothingFromAPartialReport(): void {
		$this->filesystem->given("/project/src/Excluded.php", "<?php class Excluded {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame([], $this->reading(["src/Cache/Key.php"])->snapshot->watched());
	}

	/**
	 * The same list that keeps a file out of the engine keeps it out of here,
	 * which is the whole reason the two are one declaration. Left in the report
	 * it would be covered in full and banked — recording that every mutant in
	 * it died when nothing ever made one.
	 *
	 * @return void
	 */
	public function testKeysNothingForAFileTheProjectExcluded(): void {
		$this->filesystem->given("/project/mule.json", '{"exclude": ["src/Cache/Key.php"]}');
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		$reading = $this->reading();

		self::assertSame([], $reading->snapshot->paths());
		self::assertSame([], $reading->unproven());
		self::assertSame([], $reading->snapshot->coverage(), "nor does it count towards the lines covered.");
	}

	public function testExcludesEverythingUnderAnExcludedDirectory(): void {
		$this->filesystem->given("/project/mule.json", '{"exclude": ["src/Cache"]}');
		$this->filesystem->given("/project/src/Project.php", "<?php class Project {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);
		$this->givenReport("Project.php", "Project.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		self::assertSame(["src/Project.php"], $this->reading()->snapshot->paths());
	}

	/**
	 * A directory's name is a prefix of its neighbour's, so the match has to be
	 * on a whole path segment: excluding `src/Cache` must not take `src/Caches`
	 * with it.
	 *
	 * @return void
	 */
	public function testDoesNotExcludeAFileWhoseNameMerelyStartsTheSameWay(): void {
		$this->filesystem->given("/project/mule.json", '{"exclude": ["src/Cache"]}');
		$this->filesystem->given("/project/src/Caches.php", "<?php class Caches {}");
		$this->givenReport("Caches.php", "Caches.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		self::assertSame(["src/Caches.php"], $this->reading()->snapshot->paths());
	}

	public function testRejectsACoveringTestTheAutoloaderCannotPlace(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Other\\Thing::testOne"]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The project's autoloader cannot place the covering test Other\\Thing, so mule cannot find its file.");

		$this->reading();
	}

	/**
	 * @param string $report Where the per-file report sits, relative to the coverage directory.
	 * @param string $name The source file's name.
	 * @param string $path Its directory, relative to the source root.
	 * @param list<string> $coveringTests
	 * @return void
	 */
	private function givenReport(string $report, string $name, string $path, array $coveringTests): void {
		$this->filesystem->given("/run/coverage-xml/{$report}.xml", CoverageXml::file($name, $path, $coveringTests));
	}

	/**
	 * A report for a `Key.php` with a method the tests do not enter.
	 *
	 * @return void
	 */
	private function givenPartialReport(): void {
		$this->filesystem->given("/run/coverage-xml/Cache/Key.php.xml", CoverageXml::partialFile(
			"Key.php",
			"/Cache",
			["Acme\\Tests\\KeyTest::testOne"],
			4,
			1,
			[["name" => "one", "start" => 12, "end" => 19, "executable" => 3, "executed" => 0]],
		));
	}

	/**
	 * @param list<string>|null $only
	 * @return Reading
	 */
	private function reading(?array $only = NULL): Reading {
		$project = Project::containing("/project", $this->filesystem);
		$classes = new MappedClasses([
			"Acme\\Tests\\KeyTest" => "/project/tests/KeyTest.php",
			"Acme\\Tests\\GateTest" => "/project/tests/GateTest.php",
		], ["/project/src", "/project/tests"], ["infection/infection" => "0.32.7"]);
		$universe = new Universe($project, $this->filesystem, $classes);

		$configuration = Configuration::load($project, $this->filesystem, new CoveringProcesses($this->filesystem));

		return (new Keying($project, $this->filesystem, $classes, $universe, $this->declared(), $configuration))
			->of("/run/coverage-xml", $only);
	}

	/**
	 * The snapshot read back out of the JSON it writes, which is the shape the
	 * store keeps and therefore the one worth asserting.
	 *
	 * @return array{verified: array<mixed>, tests: array<mixed>, unproven: array<mixed>, watched: array<mixed>, configuration: array<mixed>, coverage: array<mixed>, engine: array<mixed>}
	 */
	private function decoded(): array {
		$decoded = json_decode(StoreFormat::write($this->reading()->snapshot), TRUE);

		self::assertIsArray($decoded);
		self::assertIsArray($decoded["verified"]);
		self::assertIsArray($decoded["tests"]);
		self::assertIsArray($decoded["unproven"]);
		self::assertIsArray($decoded["watched"]);
		self::assertIsArray($decoded["configuration"]);
		self::assertIsArray($decoded["coverage"]);
		self::assertIsArray($decoded["engine"]);

		return [
			"verified" => $decoded["verified"],
			"tests" => $decoded["tests"],
			"unproven" => $decoded["unproven"],
			"watched" => $decoded["watched"],
			"configuration" => $decoded["configuration"],
			"coverage" => $decoded["coverage"],
			"engine" => $decoded["engine"],
		];
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private function verifiedDigestOf(string $path): string {
		$verified = $this->decoded()["verified"];

		self::assertArrayHasKey($path, $verified);
		self::assertIsArray($verified[$path]);
		self::assertIsString($verified[$path]["digest"]);

		return $verified[$path]["digest"];
	}

	/**
	 * @param string $path
	 * @return array{source: string, touches: list<string>}
	 */
	private function testEntry(string $path): array {
		$tests = $this->decoded()["tests"];

		self::assertArrayHasKey($path, $tests);
		self::assertIsArray($tests[$path]);
		self::assertIsString($tests[$path]["source"]);
		self::assertIsArray($tests[$path]["touches"]);

		$touches = [];

		foreach($tests[$path]["touches"] as $touched) {
			self::assertIsString($touched);

			$touches[] = $touched;
		}

		return ["source" => $tests[$path]["source"], "touches" => $touches];
	}

	/**
	 * @return Declared
	 */
	private function declared(): Declared {
		return new Declared(Project::containing("/project", $this->filesystem), $this->filesystem, new MappedClasses([], [], ["infection/infection" => "0.32.7"]));
	}
}
