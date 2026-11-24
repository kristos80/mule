<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

/**
 * A method or function no test reaches, or does not reach all of.
 *
 * **This is explanation, not evidence.** Whether a file counts as covered is
 * decided from the file's own totals; these ranges exist so that mule can say
 * *where* the gap is instead of leaving a file permanently unverified with no
 * reason attached.
 */
final readonly class Unreached {

	/**
	 * @param string $name The method or function.
	 * @param int $from Its first line.
	 * @param int $to Its last line.
	 * @param int $lines How many of its executable lines nothing reached.
	 */
	public function __construct(
		public string $name,
		public int $from,
		public int $to,
		public int $lines,
	) {}

	/**
	 * As the store records it.
	 *
	 * @return array{name: string, from: int, to: int, lines: int}
	 */
	public function toArray(): array {
		return ["name" => $this->name, "from" => $this->from, "to" => $this->to, "lines" => $this->lines];
	}

	/**
	 * How a person reads it.
	 *
	 * @return string
	 */
	public function described(): string {
		return "{$this->name}() lines {$this->from}-{$this->to}, {$this->lines} unreached";
	}
}
