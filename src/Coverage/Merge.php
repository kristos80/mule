<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\Failure;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * Puts a set of per-test captures back together into one.
 *
 * **This is the half of the pipeline the engine actually reads.** The shards are
 * mule's unit of caching; the merge is what Infection is handed, through
 * `--coverage` and `--skip-initial-tests` (M1). Verified at scale: a merge of 334
 * shards drove a 6,720-mutant run, and over 306 files produced exactly the same
 * 1,174 mutant identities as a monolithic capture, with an identical verdict for
 * every mutant that ran (M2).
 *
 * **Attribution merges; timings do not.** What comes out of here is the
 * `<covered by>` half of a report. Test *times* must come from a single reference
 * capture, because summing them across shards inflates them and Infection then
 * silently discards mutants that look slow — see M12, M13 and §5. Nothing in this
 * class touches JUnit, and that is the point.
 */
final readonly class Merge {

	/**
	 * **Project-level facts are given, not taken from whichever shard came
	 * first.** An earlier version read the build information off the leading
	 * shard, which the mutation gate then showed to be a preference nothing
	 * could observe: every shard has been through `Capture::of()`, so every
	 * shard declares the same format, and first-or-last was a decision with no
	 * behaviour behind it. Asking for it is the same discipline M9 applies to
	 * the base path.
	 *
	 * @param Capture $template Where the merged capture's build information comes from.
	 * @param iterable<Capture> $shards The shards to reassemble, in any order.
	 */
	public function __construct(
		private Capture $template,
		private iterable $shards,
	) {}

	/**
	 * One capture carrying every shard's coverage and test results.
	 *
	 * **`$basePath` is given, never derived.** `php-code-coverage` works it out
	 * from the set of files it can see, so a merge of some-but-not-all shards
	 * would answer a deeper directory than the project's source root and
	 * silently relocate every path in the rendered report — see M9.
	 *
	 * @param string $basePath The configured source root.
	 * @return Capture
	 */
	public function into(string $basePath): Capture {
		$merged = new ProcessedCodeCoverageData();
		$results = [];
		$any = FALSE;

		foreach($this->shards as $shard) {
			$any = TRUE;
			$merged->merge($shard->coverage);

			foreach($shard->testResults as $id => $result) {
				$this->assertUnclaimed($results, $id);

				$results[$id] = $result;
			}
		}

		if(!$any) {
			throw new Failure("There is nothing to merge: no coverage shards were given.");
		}

		// **Sorted, so the merge does not depend on the order it was handed.**
		// A warm run reassembles a mix of fresh and cached shards, and which is
		// which is a property of the edit rather than of the project. The test
		// list reaches the rendered report, and determinism is the aim: the same
		// shards must give the same bytes however they arrive.
		ksort($results);

		return $this->template->with($merged, $results, $basePath);
	}

	/**
	 * **A test may be claimed by one shard only.** Shards are cut per test
	 * class, so the same test appearing twice means the set is inconsistent — a
	 * stale shard kept beside the fresh one that replaced it, most likely.
	 * Merging them anyway would let one silently win, and which one would depend
	 * on iteration order.
	 *
	 * The results gathered so far are the record of what has been claimed; there
	 * is no second set alongside them, because a set whose values are never read
	 * is a set whose values cannot be got wrong.
	 *
	 * @param array<string, mixed> $results
	 * @param string $id
	 * @return void
	 */
	private function assertUnclaimed(array $results, string $id): void {
		if(isset($results[$id])) {
			throw new Failure("The test \"{$id}\" is claimed by more than one coverage shard.");
		}
	}
}
