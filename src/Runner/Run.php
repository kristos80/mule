<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Cache\Key;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Standing;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Soundness;
use Kristos80\Mule\Engine\Gate;

/**
 * One run, start to finish.
 *
 * Read the store, ask what is outstanding, measure only that, hand the engine a
 * report mule rendered, check what came back, and write down only what was
 * proven.
 *
 * **The cheap question is asked first and can end the run.** Answering "nothing
 * has changed" costs a few hundred digests — no coverage driver, no test process,
 * measured at 0.4 s on a 613-file project — which is the whole reason the store
 * records what each test executes.
 *
 * **One rule computes the keys, on both sides of the cache.** A run that keyed a
 * file differently from the way `Cache\Standing` keys it would bank verdicts that
 * can never match again, or leave verdicts standing nobody earned — so both build
 * the key out of a `Snapshot` and its `coveredBy`/`dependenciesOf`, and the only
 * difference between them is whether that snapshot came off disk or out of a
 * coverage pass.
 */
final readonly class Run {

	/**
	 * @param Configuration $configuration
	 * @param Universe $universe
	 * @param Coverage $coverage
	 * @param Soundness $soundness
	 * @param Engine $engine
	 * @param Store $store
	 * @param Gate $gate
	 * @param non-empty-string $toolchain
	 */
	public function __construct(
		private Configuration $configuration,
		private Universe $universe,
		private Coverage $coverage,
		private Soundness $soundness,
		private Engine $engine,
		private Store $store,
		private Gate $gate,
		private string $toolchain,
	) {}

	/**
	 * @param bool $whole Re-prove everything, rather than only what is owed.
	 * @return Finding
	 */
	public function __invoke(bool $whole = FALSE): Finding {
		$digests = $this->universe->digests();
		$stored = $this->store->read($this->toolchain);
		$standing = Standing::of($stored, $digests, $this->toolchain);
		$partial = !$whole && $standing->usable && $stored->tests !== [];

		if($partial && $standing->outstanding === []) {
			return Finding::settled($stored, $standing);
		}

		$measured = $partial
			? $this->coverage->refresh(
				$this->dirty($stored, $standing, $digests),
				array_keys($stored->tests),
				$stored->tests,
			)
			: $this->coverage->whole();

		$owed = $this->keys($partial ? $standing->outstanding : $this->universe->sources(), $measured, $digests);

		// **Before the engine, because G3 is about the report it is being handed.**
		// A shard describing a version of a file that is gone makes every mutant
		// the engine then runs a mutant of the wrong lines, and that is cheaper to
		// refuse than to explain afterwards.
		$uncovered = $this->soundness->uncovered($measured->capture, array_keys($owed));
		$outcome = $this->engine->over($measured, $partial ? array_keys($owed) : []);
		$banked = $this->unmoved($owed, $measured);

		return Finding::proven(
			$this->store->bank(
				$this->gate->proven($outcome, $banked, $this->toolchain, $measured->tests, $uncovered),
			),
			$standing,
			$banked,
			array_keys(array_diff_key($owed, $banked)),
		);
	}

	/**
	 * The test files whose coverage can no longer be trusted: the ones the store
	 * says execute something outstanding, and the ones that changed themselves.
	 *
	 * **Every one of them, not only the ones whose own bytes moved.** A file is
	 * being re-keyed and what covers it is what proves it, so a run that
	 * re-measured only some of its covering tests would read the rest of its
	 * coverage off a cache written against bytes that have since changed.
	 *
	 * A test file the tree no longer has is skipped rather than re-measured. Its
	 * shard still merges, which is harmless: everything it covered is outstanding
	 * anyway, because the digest of a covering test that is gone matches nothing.
	 *
	 * @param Snapshot $stored
	 * @param Standing $standing
	 * @param array<string, non-empty-string> $digests
	 * @return list<string>
	 */
	private function dirty(Snapshot $stored, Standing $standing, array $digests): array {
		$outstanding = array_flip($standing->outstanding);
		$dirty = [];

		foreach($stored->tests as $test => $touches) {
			if(!isset($digests[$test])) {
				continue;
			}

			foreach([$test, ...$touches] as $path) {
				if(isset($outstanding[$path])) {
					$dirty[] = $test;

					break;
				}
			}
		}

		return $dirty;
	}

	/**
	 * Of the files a run asked about, those whose key is still what it was when
	 * the engine was handed them.
	 *
	 * **The stale-edit check.** Somebody who edits a file while the engine is
	 * running gets a verdict belonging to neither version of it — the mutants came
	 * from the old bytes and the key would be written against the new ones. Taking
	 * it out of the banking list leaves the store saying nothing about it, so the
	 * next run re-proves it. Claiming it is the one outcome that cannot be
	 * recovered from.
	 *
	 * @param array<string, non-empty-string> $owed
	 * @param Measured $measured
	 * @return array<string, non-empty-string>
	 */
	private function unmoved(array $owed, Measured $measured): array {
		return array_intersect_assoc($owed, $this->keys(array_keys($owed), $measured, $this->universe->digests()));
	}

	/**
	 * What to ask the engine about, and what each one keys to.
	 *
	 * @param list<string> $paths
	 * @param Measured $measured
	 * @param array<string, non-empty-string> $digests
	 * @return array<string, non-empty-string>
	 */
	private function keys(array $paths, Measured $measured, array $digests): array {
		$fresh = new Snapshot([], [], $measured->tests, $this->toolchain);
		$keys = [];

		foreach($paths as $path) {
			if($this->configuration->excludes($path)) {
				continue;
			}

			$keys[$path] = Key::of(
				$path,
				$digests[$path] ?? "gone",
				$this->digestsOf($fresh->dependenciesOf($path), $digests),
				$this->digestsOf($fresh->coveredBy($path), $digests),
				$this->toolchain,
			);
		}

		return $keys;
	}

	/**
	 * @param list<string> $paths
	 * @param array<string, non-empty-string> $digests
	 * @return array<string, non-empty-string>
	 */
	private function digestsOf(array $paths, array $digests): array {
		$of = [];

		foreach($paths as $path) {
			$of[$path] = $digests[$path] ?? "gone";
		}

		return $of;
	}
}
