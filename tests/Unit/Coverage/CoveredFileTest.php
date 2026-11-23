<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\CoveredFile;
use Kristos80\Mule\Tests\Support\CoverageXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CoveredFile::class)]
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
