<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use function implode;

/**
 * Why one file is not proven.
 *
 * **A name on its own is a list; a name with a reason is something somebody can
 * act on.** The store recorded only the names, so "src/B.php is not proven" could
 * mean three mutants of forty-seven escaped, or the suite reaching half the file,
 * or both — and the difference decides whether you write an assertion or write a
 * test. §4: correctness without granularity is the failure mode that looks like
 * success.
 *
 * **Timed-out and errored mutants have no column here, by construction.** G8
 * refuses the whole run when `stats.timeOutCount` or any of the other four
 * forbidden statistics is non-zero, so a snapshot that exists at all has none of
 * them anywhere in it. Recording them would be recording zeros, and a column that
 * is always zero reads as a measurement that was taken.
 */
final readonly class Lapse {

	/**
	 * @param int<0, max> $mutants Every mutant the engine filed against the file.
	 * @param int<0, max> $killed Of those, the ones a test noticed.
	 * @param int<0, max> $escaped Mutants that ran and nothing failed.
	 * @param int<0, max> $uncovered Mutants on lines the suite does not reach.
	 * @param int<0, max> $executable Lines of the file that could be executed.
	 * @param int<0, max> $executed Of those, the ones the suite ran.
	 */
	public function __construct(
		public int $mutants,
		public int $killed,
		public int $escaped,
		public int $uncovered,
		public int $executable,
		public int $executed,
	) {}

	/**
	 * The reason, in a form a person can read.
	 *
	 * **Both halves when both apply**, because a file whose coverage has a hole
	 * *and* whose covered part has an escape needs two different things done to it.
	 *
	 * @return string
	 */
	public function because(): string {
		$reasons = [];

		if($this->escaped > 0 || $this->uncovered > 0) {
			$reasons[] = "{$this->escaped} of {$this->mutants} mutant(s) escaped"
				. ($this->uncovered > 0 ? " and {$this->uncovered} were on lines nothing runs" : "");
		}

		if($this->executed < $this->executable) {
			$reasons[] = "the suite reaches {$this->executed} of {$this->executable} line(s)";
		}

		return $reasons === [] ? "the engine filed nothing against it" : implode("; ", $reasons);
	}

	/**
	 * @return array<string, int>
	 */
	public function toArray(): array {
		return [
			"mutants" => $this->mutants,
			"killed" => $this->killed,
			"escaped" => $this->escaped,
			"uncovered" => $this->uncovered,
			"executable" => $this->executable,
			"executed" => $this->executed,
		];
	}
}
