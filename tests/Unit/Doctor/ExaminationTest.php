<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Doctor;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Soundness;
use Kristos80\Mule\Doctor\Examination;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Commands;
use Kristos80\Mule\Runner\Coverage;
use Kristos80\Mule\Runner\Shards;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\ScriptedProcesses;
use Kristos80\Mule\Tests\Support\StubClasses;
use Kristos80\Mule\Tests\Support\StubLines;
use Kristos80\Mule\Runner\Universe;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * One examination, through in-memory ports.
 *
 * `AaaTest` runs `A.php` and the shared `Hub.php`; `BbbTest` runs `B.php` and
 * `Hub.php`. `Gen.php` is excluded and the suite runs it anyway, which is the
 * sharp edge `doctor` exists to report.
 */
final class ExaminationTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem(
			files: [
				"/p/src/A.php" => "<?php // a",
				"/p/src/B.php" => "<?php // b",
				"/p/src/Hub.php" => "<?php // hub",
				"/p/src/Gen.php" => "<?php // generated",
				"/p/tests/Unit/AaaTest.php" => "<?php // aaa",
				"/p/tests/Unit/BbbTest.php" => "<?php // bbb",
			],
			directories: ["/p/src", "/p/tests/Unit"],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([0 => "AaaTest::testOne", 1 => "BbbTest::testOne"]);
		$coverage->setLineCoverage([
			"A.php" => [3 => [0 => 1]],
			"B.php" => [4 => [1 => 1], 8 => []],
			"Hub.php" => [5 => [0 => 1, 1 => 1]],
			"Gen.php" => [6 => [0 => 1]],
		]);

		return [
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/p/src",
			"codeCoverage" => $coverage,
			"testResults" => [
				"AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.5],
				"BbbTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.5],
			],
		];
	}

	/**
	 * @return Examination
	 */
	private function examination(): Examination {
		$configuration = Configuration::of(["tests" => "tests/Unit", "exclude" => ["src/Gen.php"]]);
		$project = new Project($this->filesystem, "/p");
		$processes = new ScriptedProcesses(function (): array {
			$this->filesystem->payloads["/p/.mule/cache/capture.cov"] = $this->payload();
			$this->filesystem->files["/p/.mule/cache/junit.xml"] = '<?xml version="1.0"?><testsuites>'
				. '<testsuite name="AaaTest" file="/p/tests/Unit/AaaTest.php" time="1"/>'
				. '<testsuite name="BbbTest" file="/p/tests/Unit/BbbTest.php" time="1"/></testsuites>';

			return ["status" => 0, "output" => ""];
		});

		return new Examination(
			new Universe($this->filesystem, $project, $configuration),
			new Coverage(
				$this->filesystem,
				$processes,
				new StubClasses([
					"AaaTest" => "/p/tests/Unit/AaaTest.php",
					"BbbTest" => "/p/tests/Unit/BbbTest.php",
				]),
				new Commands($configuration, "/usr/bin/php", ""),
				new Shards($this->filesystem, "/p/.mule/cache"),
				$configuration,
				$project,
				".mule/cache",
			),
			new Soundness(
				new StubLines([
					"/p/src/A.php" => [3],
					"/p/src/B.php" => [4, 8],
					"/p/src/Hub.php" => [5],
				]),
				$project,
				$configuration,
			),
		);
	}

	public function testItMeasuresHowFarAnEditReaches(): void {
		$diagnosis = ($this->examination())();

		self::assertSame(2, $diagnosis->spread->invalidates["src/A.php"]);
		self::assertSame(3, $diagnosis->spread->invalidates["src/Hub.php"]);
		self::assertSame(3, $diagnosis->spread->files);
	}

	public function testItReachesAVerdictOnWhetherACacheWouldPay(): void {
		self::assertSame("C", ($this->examination())()->tier->letter);
	}

	public function testItCountsWhatIsExcluded(): void {
		self::assertSame(["src/Gen.php"], ($this->examination())()->excluded);
	}

	/**
	 * **The sharp edge `exclude` has.** An excluded file's digest is tracked
	 * nowhere, so editing it invalidates nothing — correct for an interface and
	 * wrong for a wrapper the tests go through. The report knows which is which.
	 *
	 * @return void
	 */
	public function testItNamesAnExcludedFileTheTestsRunAnyway(): void {
		self::assertSame(["src/Gen.php"], ($this->examination())()->attributed);
	}

	public function testItNamesWhatTheSuiteDoesNotReach(): void {
		self::assertSame(["src/B.php"], array_keys(($this->examination())()->unreached()));
	}

	/**
	 * It leaves the shard cache behind, which is a side benefit rather than a
	 * decision: the capture happened, so the next `mule` run starts warm.
	 *
	 * @return void
	 */
	public function testItWritesNoStore(): void {
		($this->examination())();

		self::assertFalse($this->filesystem->exists("/p/.mule/verified.json"));
		self::assertTrue($this->filesystem->exists("/p/.mule/cache/tests_Unit_AaaTest.php.json"));
	}
}
