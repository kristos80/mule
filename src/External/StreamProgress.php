<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Override;
use function fwrite;
use function microtime;
use function round;
use function str_pad;
use function stream_isatty;
use function strlen;

/**
 * Progress on a stream: a spinner for a person, a line for a log.
 *
 * **The terminal check is the whole point of the class.** Carriage returns and
 * spinner frames are unreadable in a CI log and invaluable in a terminal, so the
 * same stage is rendered twice over — overwritten in place when somebody is
 * watching, one plain line each when the output is a file.
 *
 * One stage at a time, by construction: `begin()` closes the previous one. mule's
 * stages are sequential waits, and nesting would be inventing a shape the work
 * does not have.
 *
 * Excluded from coverage and mutation alongside the other wrappers.
 */
final class StreamProgress implements ProgressInterface {

	/**
	 * Braille frames: one character wide whatever the terminal, which a sequence
	 * of ASCII slashes is not.
	 */
	private const array FRAMES = ["⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"];

	private string $stage = "";

	private float $began = 0.0;

	private int $frame = 0;

	private bool $watched;

	/**
	 * @param resource $stream
	 */
	public function __construct(
		private mixed $stream,
	) {
		$this->watched = stream_isatty($stream);
	}

	#[Override]
	public function begin(string $what): void {
		$this->done();

		$this->stage = $what;
		$this->began = microtime(TRUE);
		$this->frame = 0;

		$this->watched ? $this->spin() : fwrite($this->stream, "{$what}…\n");
	}

	#[Override]
	public function tick(): void {
		if($this->watched && $this->stage !== "") {
			$this->frame++;

			$this->spin();
		}
	}

	#[Override]
	public function done(): void {
		if($this->stage === "") {
			return;
		}

		$closed = $this->stage;
		$this->stage = "";

		if($this->watched) {
			fwrite($this->stream, "\r" . str_pad("{$closed} — {$this->elapsed()}s", strlen($closed) + 24) . "\n");
		}
	}

	/**
	 * @return void
	 */
	private function spin(): void {
		fwrite(
			$this->stream,
			"\r" . self::FRAMES[$this->frame % count(self::FRAMES)] . " {$this->stage} ({$this->elapsed()}s)",
		);
	}

	/**
	 * @return string
	 */
	private function elapsed(): string {
		return (string) round(microtime(TRUE) - $this->began, 1);
	}
}
