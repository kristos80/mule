<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Dom\DOMException;
use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\Tests\Support\CoverageXml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Report::class)]
final class ReportTest extends TestCase {

	public function testReadsTheSourceRootTheReportNames(): void {
		self::assertSame("/project/src", Report::sourceRoot(CoverageXml::index("/project/src")));
	}

	public function testReadsASourceRootThatIsNotTheTopOfTheTree(): void {
		self::assertSame("/project/src/Cache", Report::sourceRoot(CoverageXml::index("/project/src/Cache")));
	}

	public function testDropsATrailingSeparatorFromTheSourceRoot(): void {
		self::assertSame("/project/src", Report::sourceRoot(CoverageXml::index("/project/src/")));
	}

	public function testRejectsAnIndexNamingNoProject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage report names no project.");

		Report::sourceRoot('<?xml version="1.0"?><phpunit/>');
	}

	public function testRejectsAnIndexNamingNoSourceRoot(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage report names no source root.");

		Report::sourceRoot('<?xml version="1.0"?><phpunit><project/></phpunit>');
	}

	public function testRejectsAnIndexItCannotParse(): void {
		$this->expectException(DOMException::class);

		Report::sourceRoot("{not xml");
	}

	public function testFindsThePerFileReportsAmongTheOtherEntries(): void {
		$reports = Report::reports([
			"/run/coverage-xml/index.xml",
			"/run/coverage-xml/Cache/Key.php.xml",
			"/run/coverage-xml/Cache",
			"/run/coverage-xml/Project.php.xml",
			"/run/coverage-xml/dashboard.html",
		]);

		self::assertSame(["/run/coverage-xml/Cache/Key.php.xml", "/run/coverage-xml/Project.php.xml"], $reports);
	}

	public function testSortsTheReportsWhateverOrderTheFilesystemGaveThem(): void {
		$reports = Report::reports([
			"/run/coverage-xml/Project.php.xml",
			"/run/coverage-xml/Cache/Verdicts.php.xml",
			"/run/coverage-xml/Cache/Key.php.xml",
		]);

		self::assertSame([
			"/run/coverage-xml/Cache/Key.php.xml",
			"/run/coverage-xml/Cache/Verdicts.php.xml",
			"/run/coverage-xml/Project.php.xml",
		], $reports);
	}

	public function testFindsNoReportsInADirectoryHoldingNone(): void {
		self::assertSame([], Report::reports(["/run/coverage-xml/index.xml"]));
	}
}
