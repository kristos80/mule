<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\CoverageReportInterface;
use Override;

/**
 * Remembers what it was asked to render, so a test can assert the payload and
 * the directory without `php-code-coverage` writing six hundred documents.
 */
final class RecordingReports implements CoverageReportInterface {

	/**
	 * @var list<array{payload: array<string, mixed>, directory: string}>
	 */
	public array $rendered = [];

	#[Override]
	public function renderXml(array $payload, string $directory): void {
		$this->rendered[] = ["payload" => $payload, "directory" => $directory];
	}
}
