<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

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
	 * Writes `coverage-xml/` under the directory given.
	 *
	 * @param array<string, mixed> $payload A `Capture` payload: the shape `--coverage-php` writes.
	 * @param string $directory The report directory; `coverage-xml` is created inside it.
	 * @return void
	 */
	public function renderXml(array $payload, string $directory): void;
}
