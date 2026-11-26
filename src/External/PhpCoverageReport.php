<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Kristos80\Mule\Coverage\Capture;
use Override;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Report\Facade;

/**
 * `php-code-coverage` rendering its own XML report.
 *
 * **One call, because that call writes files.** `Facade::renderXml()` creates the
 * directory and writes a document per source file, so there is no transformation
 * here to test and nothing a mutant could alter that an assertion would catch.
 * Excluded from coverage and mutation for the same reason as `PhpFilesystem`.
 *
 * `FALSE` for the source: the documents carry line data only, which is all
 * Infection reads, and is what its own initial run asks for with
 * `--exclude-source-from-xml-coverage`. On the measured application it is the
 * difference between a 41 MB report directory and one Infection is just as happy
 * with.
 *
 * Everything else is left to default. The build date, the runtime and the driver
 * names reach `<build>` and `<driver>`, which no part of Infection reads — and a
 * timestamp mule invented would be the one thing in the report that changed
 * between two identical runs.
 */
final readonly class PhpCoverageReport implements CoverageReportInterface {

	/**
	 * **The build information is assembled here, from literals.**
	 * `fromSerializedData()` never reads it — it takes `codeCoverage`,
	 * `testResults` and `basePath` and builds the report from those — but its
	 * parameter is typed for the whole serialized shape, so the fields have to be
	 * there. Inventing them in the wrapper keeps the three that matter precisely
	 * typed on the other side of the seam, with nothing suppressed.
	 */
	#[Override]
	/**
	 * @param ProcessedCodeCoverageData $coverage
	 * @param array<non-empty-string, array{size: string, status: string, time: float}> $testResults
	 * @param string $basePath
	 * @param non-empty-string $target
	 * @return void
	 */
	public function renderXml(ProcessedCodeCoverageData $coverage, array $testResults, string $basePath, string $target): void {
		Facade::fromSerializedData([
			"buildInformation" => [
				"timestamp" => "",
				"runtime" => ["name" => "", "version" => "", "vendorUrl" => ""],
				"phpCodeCoverage" => [
					"version" => "",
					"serializationFormat" => Capture::FORMAT,
					"driverInformation" => ["name" => "mule", "version" => "mule"],
				],
			],
			"basePath" => $basePath,
			"codeCoverage" => $coverage,
			"testResults" => $testResults,
		])->renderXml($target, FALSE);
	}
}
