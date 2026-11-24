<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Reading;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Universe;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\CoverageXml;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\MappedClasses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeyingTest extends TestCase {

	private const string SOURCE = "<?php class Key {}";

	private const string KEY_TEST = "<?php class KeyTest {}";

	private const string GATE_TEST = "<?php class GateTest {}";

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", "{}")
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

		// Exactly these: what the project declares, plus every autoloaded file
		// the report never mentioned — a file excluded from coverage, and a
		// test that ran nothing. Not the proven file, and not its test.
		self::assertSame(
			["composer.json", "src/Excluded.php", "tests/GateTest.php"],
			array_keys($this->decoded()["watched"]),
		);
	}

	/**
	 * mule.json is the one file meant to differ between machines, so watching
	 * it would make a committed store unusable wherever it differs.
	 *
	 * @return void
	 */
	public function testDoesNotWatchItsOwnConfiguration(): void {
		$this->filesystem->given("/project/mule.json", '{"options": []}');
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

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

	public function testWatchesNothingFromAPartialReport(): void {
		$this->filesystem->given("/project/src/Excluded.php", "<?php class Excluded {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame([], $this->reading(["src/Cache/Key.php"])->snapshot->watched());
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
		], ["/project/src", "/project/tests"]);
		$universe = new Universe($project, $this->filesystem, $classes);

		return (new Keying($project, $this->filesystem, $classes, $universe))->of("/run/coverage-xml", $only);
	}

	/**
	 * The snapshot read back out of the JSON it writes, which is the shape the
	 * store keeps and therefore the one worth asserting.
	 *
	 * @return array{verified: array<mixed>, tests: array<mixed>, unproven: array<mixed>, watched: array<mixed>}
	 */
	private function decoded(): array {
		$decoded = json_decode(StoreFormat::write($this->reading()->snapshot), TRUE);

		self::assertIsArray($decoded);
		self::assertIsArray($decoded["verified"]);
		self::assertIsArray($decoded["tests"]);
		self::assertIsArray($decoded["unproven"]);
		self::assertIsArray($decoded["watched"]);

		return [
			"verified" => $decoded["verified"],
			"tests" => $decoded["tests"],
			"unproven" => $decoded["unproven"],
			"watched" => $decoded["watched"],
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
}
