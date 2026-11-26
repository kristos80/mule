<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Override;
use function fwrite;

/**
 * A stream, written a line at a time.
 *
 * **One primitive**, so it is excluded from coverage and from mutation like the
 * other wrappers — named in both `phpunit.xml` and `infection.json5`.
 */
final readonly class StreamOutput implements OutputInterface {

	/**
	 * @param resource $stream
	 */
	public function __construct(
		private mixed $stream,
	) {}

	#[Override]
	public function line(string $line): void {
		fwrite($this->stream, "{$line}\n");
	}
}
