<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\CoverageReportInterface;
use Override;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * Remembers what it was asked to render, so a test can assert the payload and
 * the directory without `php-code-coverage` writing six hundred documents.
 */
final class RecordingReports implements CoverageReportInterface {

	/**
	 * @var list<array{
	 *     coverage: ProcessedCodeCoverageData,
	 *     testResults: array<non-empty-string, array{size: string, status: string, time: float}>,
	 *     basePath: string,
	 *     target: string,
	 * }>
	 */
	public array $rendered = [];

	#[Override]
	public function renderXml(
		ProcessedCodeCoverageData $coverage,
		array $testResults,
		string $basePath,
		string $target,
	): void {
		$this->rendered[] = [
			"coverage" => $coverage,
			"testResults" => $testResults,
			"basePath" => $basePath,
			"target" => $target,
		];
	}
}
