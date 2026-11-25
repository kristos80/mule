<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

/**
 * How much of something ran: the lines that can be executed at all, and how
 * many of them a test reached.
 *
 * **One file's and the whole project's are the same thing.** Adding is
 * {@see self::plus()}, so the project's answer is its files' answers summed
 * rather than a second number read from somewhere else. A coverage report's own
 * index carries project totals, and they are the obvious source and the wrong
 * one: a narrowed run asks the test runner for a subset of the suite, and the
 * index then totals every source file in the tree against only the tests that
 * ran. Summing the per-file reports mule is authoritative about — and taking the
 * rest from the store — is the only arithmetic that stays true of a partial run.
 */
final readonly class Reach {

	/**
	 * @param int $executable How many lines can be executed at all.
	 * @param int $executed How many of those the tests reached.
	 */
	public function __construct(
		public int $executable,
		public int $executed,
	) {}

	/**
	 * Nothing measured: what a sum starts from, and what a store written before
	 * mule recorded any of this reads as.
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self(0, 0);
	}

	/**
	 * The two added together.
	 *
	 * @param self $other
	 * @return self
	 */
	public function plus(self $other): self {
		return new self($this->executable + $other->executable, $this->executed + $other->executed);
	}

	/**
	 * Whether every executable line ran.
	 *
	 * A file with nothing executable in it — an interface, a file of constants —
	 * is complete, and contributes nothing either way to a sum.
	 *
	 * @return bool
	 */
	public function isComplete(): bool {
		return $this->executed === $this->executable;
	}

	/**
	 * Whether there is anything here to report a proportion of.
	 *
	 * @return bool
	 */
	public function isMeasured(): bool {
		return $this->executable > 0;
	}

	/**
	 * Whether this much of it ran, as a percentage.
	 *
	 * **Cross-multiplied rather than divided**, so the comparison is exact
	 * whole-number arithmetic. A project asked for 80 % and sitting on exactly
	 * four fifths should not turn on whether `0.8` is representable.
	 *
	 * @param int $percentage
	 * @return bool
	 */
	public function reaches(int $percentage): bool {
		return $this->executed * 100 >= $percentage * $this->executable;
	}

	/**
	 * As the store writes it.
	 *
	 * @return array{executable: int, executed: int}
	 */
	public function toArray(): array {
		return ["executable" => $this->executable, "executed" => $this->executed];
	}
}
