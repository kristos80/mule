<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * What the store says, at one moment.
 *
 * A snapshot may speak for the whole project or for the part of it a run
 * touched, and the difference decides what merging means.
 */
final readonly class Snapshot {

	/**
	 * Files this snapshot proves, keyed by project-relative path.
	 *
	 * @var array<string, Verdict>
	 */
	public array $verdicts;

	/**
	 * Files it examined and could *not* prove.
	 *
	 * @var list<string>
	 */
	public array $unproven;

	/**
	 * **Canonical on the way in, once.** The store is committed, so two machines
	 * that proved the same files have to write the same bytes — and the order
	 * verdicts arrive in is the order a coverage report or a cache happened to
	 * list them. Sorting here rather than at each use leaves one place to get it
	 * right and no second sort to disagree with.
	 *
	 * @param array<string, Verdict> $verdicts
	 * @param list<string> $unproven
	 * @param non-empty-string $toolchain The environment every verdict here was proven under.
	 */
	public function __construct(
		array $verdicts,
		array $unproven,
		public string $toolchain,
	) {
		ksort($verdicts);

		// `sort()` reindexes, so wrapping this in `array_values()` would be an
		// operation with no result behind it — and the gate said so.
		$unique = array_unique($unproven);
		sort($unique);

		$this->verdicts = $verdicts;
		$this->unproven = $unique;
	}

	/**
	 * This snapshot with a later one written over it.
	 *
	 * **Written over, never replaced.** A run that re-proved three files knows
	 * nothing about the other four hundred, so its empty sections must not erase
	 * what an earlier run recorded.
	 *
	 * **Both directions move.** A path belongs to exactly one of proven and
	 * unproven, and the newer answer is the true one — so a file the later run
	 * proved stops being unproven, and a file it could not prove stops being
	 * proven. Without the second half a store only ever grows, and nothing can
	 * ever take a claim back.
	 *
	 * **A different toolchain replaces outright.** Verdicts proven under another
	 * engine, driver or mutator set are not old, they are answers to a different
	 * question. Merging them forward is how a store comes to claim more than
	 * anything ever proved — measured on a real project, where a gate change left
	 * 610 verdicts standing of which one run had earned 221.
	 *
	 * @param self $newer
	 * @return self
	 */
	public function with(self $newer): self {
		if($newer->toolchain !== $this->toolchain) {
			return $newer;
		}

		$verdicts = $this->verdicts;

		foreach($newer->unproven as $path) {
			unset($verdicts[$path]);
		}

		foreach($newer->verdicts as $path => $verdict) {
			$verdicts[$path] = $verdict;
		}

		return new self($verdicts, $this->mergedUnproven($newer, $verdicts), $this->toolchain);
	}

	/**
	 * Whether this snapshot was proven under the environment given.
	 *
	 * **Asked separately from vouching**, because the answer is all-or-nothing
	 * and the run that finds out should be able to say so rather than watch every
	 * file quietly read as stale.
	 *
	 * @param non-empty-string $toolchain
	 * @return bool
	 */
	public function describes(string $toolchain): bool {
		return $this->toolchain === $toolchain;
	}

	/**
	 * Whether this snapshot still vouches for a file keying to `$key`.
	 *
	 * @param string $path
	 * @param non-empty-string $key
	 * @return bool
	 */
	public function vouchesFor(string $path, string $key): bool {
		return isset($this->verdicts[$path]) && $this->verdicts[$path]->holds($key);
	}

	/**
	 * Of the paths given with their current keys, those this snapshot cannot
	 * vouch for — because it never proved them, or because they have moved.
	 *
	 * @param array<string, non-empty-string> $keys Path to the key it has now.
	 * @return list<string>
	 */
	public function outstanding(array $keys): array {
		$outstanding = [];

		foreach($keys as $path => $key) {
			if(!$this->vouchesFor($path, $key)) {
				$outstanding[] = $path;
			}
		}

		return $outstanding;
	}

	/**
	 * @return int<0, max>
	 */
	public function mutants(): int {
		$mutants = 0;

		foreach($this->verdicts as $verdict) {
			$mutants += $verdict->mutants;
		}

		return $mutants;
	}

	/**
	 * The unproven list after a merge: whatever either run could not prove, less
	 * anything now proven. Sorting and de-duplicating is the constructor's.
	 *
	 * @param self $newer
	 * @param array<string, Verdict> $verdicts
	 * @return list<string>
	 */
	private function mergedUnproven(self $newer, array $verdicts): array {
		$unproven = [];

		foreach([...$this->unproven, ...$newer->unproven] as $path) {
			if(!isset($verdicts[$path])) {
				$unproven[] = $path;
			}
		}

		return $unproven;
	}
}
