<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Report written to a stream, normally this process's standard output or
 * standard error.
 */
final readonly class StreamOutput implements OutputInterface {

	/**
	 * @param resource $stream
	 */
	public function __construct(
		private mixed $stream,
	) {}

	/**
	 * @param string $text
	 * @return void
	 */
	public function line(string $text): void {
		fwrite($this->stream, "{$text}\n");
	}
}
