<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Cache\Key;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Standing;
use Kristos80\Mule\Cache\Store;
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
	 * @param Universe $universe
	 * @param Coverage $coverage
	 * @param Soundness $soundness
	 * @param Engine $engine
	 * @param Store $store
	 * @param Gate $gate
	 * @param non-empty-string $toolchain
	 */
	public function __construct(
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
		$gated = $this->universe->sources();
		$stored = $this->store->read($this->toolchain);
		$standing = Standing::of($stored, $digests, $gated, $this->toolchain);
		$live = $this->live($stored, $digests);
		$partial = !$whole && $standing->usable && $live !== [];

		if($partial && $standing->outstanding === [] && $standing->unmeasured === []) {
			return Finding::settled($stored, $standing);
		}

		$measured = $partial
			? $this->coverage->refresh($live, $digests)
			: $this->coverage->whole($this->universe->tests());

		// **What is owed is decided after measuring, never before.** The question
		// Standing answers is "may this run skip measuring altogether", and it has
		// to answer it from the store — which is the picture as it was. Once a
		// coverage pass has happened the picture is exact, and a file's key can be
		// compared against the verdict the store holds for it.
		$keys = $this->keys($gated, $measured, $digests);
		$owed = $whole ? $keys : $this->unvouched($keys, $stored);

		if($owed === []) {
			return Finding::proven($this->recorded($measured), $standing, [], []);
		}

		// **Before the engine, because G3 is about the report it is being handed.**
		// A shard describing a version of a file that is gone makes every mutant
		// the engine then runs a mutant of the wrong lines, and that is cheaper to
		// refuse than to explain afterwards.
		$uncovered = $this->soundness->uncovered($measured->capture, array_keys($owed));
		$outcome = $this->engine->over($measured, $whole ? [] : array_keys($owed));
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
	 * Of the keys a run computed, those the store cannot vouch for.
	 *
	 * **This is where a file that acquired a covering test gets noticed.** Editing
	 * one test so that it also runs `B.php` moves `B.php`'s closure — it now
	 * depends on everything that test runs — while `B.php`'s own bytes and the
	 * store's record of what covers it are both untouched. Asked before the
	 * coverage pass, nothing about `B.php` has moved; asked after it, its key has.
	 *
	 * @param array<string, non-empty-string> $keys
	 * @param Snapshot $stored
	 * @return array<string, non-empty-string>
	 */
	private function unvouched(array $keys, Snapshot $stored): array {
		$unvouched = [];

		foreach($keys as $path => $key) {
			if(!$stored->vouchesFor($path, $key)) {
				$unvouched[$path] = $key;
			}
		}

		return $unvouched;
	}

	/**
	 * The store with this run's record of what each test executes written over it,
	 * and no verdict touched.
	 *
	 * **For the run that measured and found nothing owed.** A test file that has
	 * appeared and covers nothing is the case: the store had never heard of it, so
	 * the run could not be partial, and once measured it turns out to change no
	 * key. Recording it anyway is what stops the next run going wide for the same
	 * reason.
	 *
	 * @param Measured $measured
	 * @return Snapshot
	 */
	private function recorded(Measured $measured): Snapshot {
		return $this->store->bank(new Snapshot([], [], $measured->tests, $this->toolchain));
	}

	/**
	 * What the store says each test executes, less the tests the tree no longer
	 * has.
	 *
	 * **A deleted test file is dropped here rather than merged and skipped.** Its
	 * shard is still in the cache, and reassembling it would hand the engine a
	 * report naming tests that cannot be run — whereupon Infection runs each
	 * mutant against a missing test file, the run fails, and a failing run is
	 * scored as a kill. Dropping it instead leaves whatever it covered looking
	 * unreached, which is exactly what it now is.
	 *
	 * @param Snapshot $stored
	 * @param array<string, non-empty-string> $digests
	 * @return array<string, Footprint>
	 */
	private function live(Snapshot $stored, array $digests): array {
		$live = [];

		foreach($stored->tests as $test => $footprint) {
			if(isset($digests[$test])) {
				$live[$test] = $footprint;
			}
		}

		return $live;
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
	 * **Nothing filters out an excluded path here, and nothing needs to.** The
	 * cold list comes from `Universe::sources()` and the partial list from
	 * `Cache\Standing`, and both are already the files mule is answerable for —
	 * a second check was a branch the gate proved nothing could reach.
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
