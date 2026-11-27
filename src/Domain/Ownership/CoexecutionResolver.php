<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Ownership;

use function sort;

/**
 * Every other source file that some covering test of a given file also executes.
 *
 * **This is the term that makes a verification identity sound, and the reason it
 * is its own class is that leaving it out is invisible.** A source file executed
 * only to build a fixture — a mapper called by a test factory, a value object a
 * test constructs — is never an injected collaborator and is named nowhere, yet
 * its behaviour decides whether a mutant of the file under test dies. Measured
 * on the reference application: `NormalizedOrderMapper` is executed by 19 test
 * files purely because a support factory calls it, and it carries 74 mutants of
 * its own. Keying source files on their own bytes alone would have banked 87
 * stale proofs the moment that mapper changed.
 *
 * **One hop, and deliberately not two.** A file two hops away is reached only by
 * some *other* test which happens to share a file with this one. It never
 * executes in this file's tests, so it cannot change this file's verdict, and
 * including it would be waste rather than caution. Measured on the same project:
 * one hop touches a median of 11 files out of 404, while the transitive closure
 * touches 383 — which is why earlier attempts at this tool cached correctly and
 * saved nothing.
 */
final readonly class CoexecutionResolver implements CoexecutionResolverInterface {

	/**
	 * @param OwnershipMap $ownership
	 * @param string $source Project-relative.
	 * @return list<string> Sorted, and never containing `$source` itself.
	 */
	public function resolve(OwnershipMap $ownership, string $source): array {
		// **Keyed by path and holding the path**, so that deduplication and a
		// `list<string>` come out of the same structure without a cast.
		$coexecuted = [];

		foreach($ownership->owners[$source] ?? [] as $test) {
			foreach($ownership->owns[$test] ?? [] as $other) {
				$coexecuted[$other] = $other;
			}
		}

		// **The file itself is dropped rather than never added.** Its own digest is
		// already a separate term of the identity, and a file appearing in both
		// would make the hash depend on which of the two terms a reader believed
		// carried it.
		unset($coexecuted[$source]);

		// **`sort()` reindexes, so there is nothing left for `array_values()` to do.**
		// The gate showed it as an operation with no result behind it.
		$others = $coexecuted;
		sort($others);

		return $others;
	}
}
