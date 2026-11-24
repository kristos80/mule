<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Verdicts;
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

		self::assertSame(hash("sha256", self::SOURCE), $this->sourceOf("src/Cache/Key.php"));
	}

	public function testKeysACoveredFileAgainstTheFileOfEveryTestCoveringIt(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertSame(
			hash("sha256", "tests/KeyTest.php:" . hash("sha256", self::KEY_TEST)),
			$this->testsOf("src/Cache/Key.php"),
		);
	}

	public function testCountsOneCoveringFileOnceHoweverManyOfItsMethodsCover(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", [
			"Acme\\Tests\\KeyTest::testOne",
			"Acme\\Tests\\KeyTest::testTwo",
		]);

		self::assertSame(
			hash("sha256", "tests/KeyTest.php:" . hash("sha256", self::KEY_TEST)),
			$this->testsOf("src/Cache/Key.php"),
		);
	}

	public function testSortsTheCoveringFilesSoTheReportsOwnOrderCannotChangeTheDigest(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", [
			"Acme\\Tests\\KeyTest::testOne",
			"Acme\\Tests\\GateTest::testOne",
		]);

		self::assertSame(
			hash("sha256", implode("\n", [
				"tests/GateTest.php:" . hash("sha256", self::GATE_TEST),
				"tests/KeyTest.php:" . hash("sha256", self::KEY_TEST),
			])),
			$this->testsOf("src/Cache/Key.php"),
		);
	}

	public function testKeysAFileNoTestCoversAgainstAnEmptyCoveringSet(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", []);

		self::assertSame(hash("sha256", ""), $this->testsOf("src/Cache/Key.php"));
	}

	public function testKeysEveryFileTheReportHolds(): void {
		$this->filesystem->given("/project/src/Project.php", "<?php class Project {}");
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne"]);
		$this->givenReport("Project.php", "Project.php", "/", ["Acme\\Tests\\GateTest::testOne"]);

		self::assertSame(["src/Cache/Key.php", "src/Project.php"], $this->verdicts()->paths());
	}

	public function testKeysNothingFromAReportHoldingNoFiles(): void {
		self::assertSame(0, $this->verdicts()->count());
	}

	public function testReadsACoveringTestNamedWithoutAMethod(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Acme\\Tests\\KeyTest"]);

		self::assertSame(
			hash("sha256", "tests/KeyTest.php:" . hash("sha256", self::KEY_TEST)),
			$this->testsOf("src/Cache/Key.php"),
		);
	}

	public function testRejectsACoveringTestTheAutoloaderCannotPlace(): void {
		$this->givenReport("Cache/Key.php", "Key.php", "/Cache", ["Other\\Thing::testOne"]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The project's autoloader cannot place the covering test Other\\Thing, so mule cannot find its file.");

		$this->verdicts();
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
	 * @return Verdicts
	 */
	private function verdicts(): Verdicts {
		$project = Project::containing("/project", $this->filesystem);
		$classes = new MappedClasses([
			"Acme\\Tests\\KeyTest" => "/project/tests/KeyTest.php",
			"Acme\\Tests\\GateTest" => "/project/tests/GateTest.php",
		]);

		return (new Keying($project, $this->filesystem, $classes))->of("/run/coverage-xml");
	}

	/**
	 * The source digest recorded for one path, read back out of the JSON the
	 * verdicts write.
	 *
	 * @param string $path
	 * @return string
	 */
	private function sourceOf(string $path): string {
		return $this->digestOf($path, "source");
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private function testsOf(string $path): string {
		return $this->digestOf($path, "tests");
	}

	/**
	 * @param string $path
	 * @param string $half
	 * @return string
	 */
	private function digestOf(string $path, string $half): string {
		$decoded = json_decode($this->verdicts()->toJson(), TRUE);

		self::assertIsArray($decoded);
		self::assertArrayHasKey($path, $decoded);
		self::assertIsArray($decoded[$path]);
		self::assertIsString($decoded[$path][$half]);

		return $decoded[$path][$half];
	}
}
