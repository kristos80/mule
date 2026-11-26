<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * Rendering a coverage payload into the report directory Infection reads.
 *
 * **Behind a seam because `php-code-coverage` writes the files itself.**
 * `Report\Facade::renderXml()` creates directories and writes a document per
 * source file, so it is I/O rather than a transformation, and the only thing on
 * the far side of this interface is the call that performs it. What goes into the
 * payload, and where the directory is, are decisions and stay on this side.
 */
interface CoverageReportInterface {

	/**
	 * Writes one XML document per source file, plus the index, into the
	 * directory given.
	 *
	 * **The target is the directory itself, not its parent.** Which directory
	 * Infection looks in is M1's business and therefore a decision, so the name
	 * `coverage-xml` is chosen on the other side of this seam rather than here.
	 *
	 * @param ProcessedCodeCoverageData $coverage The merged attribution.
	 * @param array<non-empty-string, array{size: string, status: string, time: float}> $testResults
	 * @param string $basePath The configured source root (M9).
	 * @param non-empty-string $target The directory to write the documents into.
	 * @return void
	 */
	public function renderXml(
		ProcessedCodeCoverageData $coverage,
		array $testResults,
		string $basePath,
		string $target,
	): void;
}
