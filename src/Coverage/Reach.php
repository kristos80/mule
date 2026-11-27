<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use function count;
use function intdiv;

/**
 * How much of one file the suite runs.
 *
 * **Counted rather than inferred from the mutation score**, because an uncovered
 * line whose statements happen to be unmutable produces no mutant at all: the
 * engine files nothing against it, every mutant of the file is killed, and it
 * banks as proven with a chunk never executed. Infection cannot report what it
 * never generated.
 */
final readonly class Reach {

	/**
	 * @param int<0, max> $executable Lines of the file that could be executed.
	 * @param int<0, max> $executed Of those, the ones the suite ran.
	 * @param bool $meets Whether that is enough for the configured floor.
	 */
	public function __construct(
		public int $executable,
		public int $executed,
		public bool $meets,
	) {}

	/**
	 * **The floor is applied here rather than by each caller**, so there is one
	 * answer to "is this enough" and no second comparison to disagree with it.
	 *
	 * **A file with nothing executable in it meets any floor.** An interface or an
	 * enum of constants has no lines to reach, and 0 of 0 is not a gap — it is
	 * G1's business, which says so in a message naming `exclude`.
	 *
	 * **Not rounded in the project's favour.** Two lines of three is 66 %, so a
	 * floor of 67 catches it: a file one line short of the bar is short of it.
	 *
	 * @param list<int> $executable
	 * @param int<0, max> $executed
	 * @param int $floor
	 * @return self
	 */
	public static function of(array $executable, int $executed, int $floor): self {
		$lines = count($executable);

		return new self($lines, $executed, $lines === 0 || intdiv($executed * 100, $lines) >= $floor);
	}
}
