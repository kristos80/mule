<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * What one run proved about one file.
 *
 * **Only "every mutant killed by a test" is a verdict** (G8). Infection's notion
 * of detected is wider — it counts timed-out, errored and syntax-error mutants
 * as kills, and `killedByStaticAnalysis` means the tests *failed* and a type
 * checker caught it afterwards (M14, M15). None of those is proof that a test
 * would notice the change, so none of them is banked.
 *
 * `mutants` can be `0`, and that is a claim rather than an absence: the file was
 * examined and has nothing to mutate. Distinguishing it from "never examined" is
 * what lets G1 fail on a file that produced no mutants it should have.
 */
final readonly class Verdict {

	/**
	 * @param non-empty-string $key What the verdict rests on (`Key::of()`).
	 * @param int<0, max> $mutants How many were killed, which is all of them.
	 */
	public function __construct(
		public string $key,
		public int $mutants,
	) {}

	/**
	 * Whether this verdict still describes the tree, given what the file keys to
	 * now.
	 *
	 * @param non-empty-string $key
	 * @return bool
	 */
	public function holds(string $key): bool {
		return $this->key === $key;
	}
}
