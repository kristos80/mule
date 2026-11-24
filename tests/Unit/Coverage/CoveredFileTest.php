<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\CoveredFile;
use Kristos80\Mule\Tests\Support\CoverageXml;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoveredFileTest extends TestCase {

	public function testNamesTheSourceFileTheReportIsAbout(): void {
		$file = CoveredFile::of(CoverageXml::file("Key.php", "/Cache", []), "/project/src", "Key.php.xml");

		self::assertSame("/project/src/Cache/Key.php", $file->path);
	}

	public function testNamesASourceFileSittingAtTheSourceRoot(): void {
		$file = CoveredFile::of(CoverageXml::file("Project.php", "/", []), "/project/src", "Project.php.xml");

		self::assertSame("/project/src/Project.php", $file->path);
	}

	public function testReadsEveryCoveringTest(): void {
		$xml = CoverageXml::file("Key.php", "/Cache", ["Acme\\Tests\\KeyTest::testOne", "Acme\\Tests\\GateTest::testTwo"]);

		self::assertSame(
			["Acme\\Tests\\KeyTest::testOne", "Acme\\Tests\\GateTest::testTwo"],
			CoveredFile::of($xml, "/project/src", "Key.php.xml")->coveringTests,
		);
	}

	public function testNamesACoveringTestOnceHoweverManyLinesItCovers(): void {
		$xml = CoverageXml::file("Key.php", "/Cache", [
			"Acme\\Tests\\KeyTest::testOne",
			"Acme\\Tests\\KeyTest::testOne",
			"Acme\\Tests\\KeyTest::testTwo",
		]);

		self::assertSame(
			["Acme\\Tests\\KeyTest::testOne", "Acme\\Tests\\KeyTest::testTwo"],
			CoveredFile::of($xml, "/project/src", "Key.php.xml")->coveringTests,
		);
	}

	public function testReadsAFileNoTestCovers(): void {
		self::assertSame([], CoveredFile::of(CoverageXml::file("Key.php", "/", []), "/src", "Key.php.xml")->coveringTests);
	}

	public function testReadsHowMuchOfTheFileTheTestsReached(): void {
		$xml = CoverageXml::partialFile("Key.php", "/", ["Acme\\Tests\\KeyTest::testOne"], 4, 1, []);
		$file = CoveredFile::of($xml, "/project/src", "Key.php.xml");

		self::assertSame(4, $file->executable);
		self::assertSame(1, $file->executed);
		self::assertFalse($file->isFullyCovered());
	}

	public function testCountsAFileEveryLineOfWhichRanAsCoveredInFull(): void {
		$xml = CoverageXml::file("Key.php", "/", ["Acme\\Tests\\KeyTest::testOne"]);

		self::assertTrue(CoveredFile::of($xml, "/project/src", "Key.php.xml")->isFullyCovered());
	}

	public function testCountsAFileWithNoExecutableLinesAsCoveredInFull(): void {
		$xml = CoverageXml::partialFile("Key.php", "/", [], 0, 0, []);

		self::assertTrue(CoveredFile::of($xml, "/project/src", "Key.php.xml")->isFullyCovered());
	}

	public function testNamesTheMethodsTheTestsDoNotReachAllOf(): void {
		$xml = CoverageXml::partialFile("Key.php", "/", [], 6, 2, [
			["name" => "reached", "start" => 10, "end" => 14, "executable" => 2, "executed" => 2],
			["name" => "missed", "start" => 20, "end" => 29, "executable" => 4, "executed" => 0],
		]);

		$unreached = CoveredFile::of($xml, "/project/src", "Key.php.xml")->unreached;

		self::assertCount(1, $unreached);
		self::assertSame("missed() lines 20-29, 4 unreached", $unreached[0]->described());
	}

	public function testNamesEveryMethodTheTestsMissRatherThanTheFirst(): void {
		$xml = CoverageXml::partialFile("Key.php", "/", [], 7, 1, [
			["name" => "first", "start" => 10, "end" => 14, "executable" => 3, "executed" => 0],
			["name" => "second", "start" => 16, "end" => 22, "executable" => 3, "executed" => 0],
		]);

		$unreached = CoveredFile::of($xml, "/project/src", "Key.php.xml")->unreached;

		self::assertSame(
			["first() lines 10-14, 3 unreached", "second() lines 16-22, 3 unreached"],
			array_map(static fn($one): string => $one->described(), $unreached),
		);
	}

	public function testNamesAMethodTheTestsOnlyReachPartOf(): void {
		$xml = CoverageXml::partialFile("Key.php", "/", [], 4, 3, [
			["name" => "half", "start" => 10, "end" => 19, "executable" => 4, "executed" => 3],
		]);

		$unreached = CoveredFile::of($xml, "/project/src", "Key.php.xml")->unreached;

		self::assertSame("half() lines 10-19, 1 unreached", $unreached[0]->described());
	}

	public function testNamesNothingUnreachedInAFileThatIsCoveredInFull(): void {
		$xml = CoverageXml::partialFile("Key.php", "/", [], 2, 2, [
			["name" => "reached", "start" => 10, "end" => 14, "executable" => 2, "executed" => 2],
		]);

		self::assertSame([], CoveredFile::of($xml, "/project/src", "Key.php.xml")->unreached);
	}

	public function testNamesAPlainFunctionTheTestsDoNotReach(): void {
		$xml = <<<XML
			<?xml version="1.0"?>
			<phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
				<file name="helpers.php" path="/">
					<totals>
						<lines executable="3" executed="0"/>
						<functions>
							<function name="slugify" start="7" end="12" executable="3" executed="0"/>
						</functions>
					</totals>
					<coverage/>
				</file>
			</phpunit>
			XML;

		$unreached = CoveredFile::of($xml, "/project/src", "helpers.php.xml")->unreached;

		self::assertCount(1, $unreached);
		self::assertSame("slugify() lines 7-12, 3 unreached", $unreached[0]->described());
	}

	public function testRejectsAReportGivingTheFileNoTotals(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage report Key.php.xml gives the file no totals.");

		CoveredFile::of('<?xml version="1.0"?><phpunit><file name="Key.php" path="/"/></phpunit>', "/src", "Key.php.xml");
	}

	public function testRejectsAReportNamingNoFile(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage report Key.php.xml names no file.");

		CoveredFile::of('<?xml version="1.0"?><phpunit/>', "/project/src", "Key.php.xml");
	}

	public function testRejectsAReportWhoseFileIsMissingAnAttribute(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage report Key.php.xml has a file with no path.");

		CoveredFile::of('<?xml version="1.0"?><phpunit><file name="Key.php"/></phpunit>', "/project/src", "Key.php.xml");
	}
}
