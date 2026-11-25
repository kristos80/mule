<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Failure;

/**
 * What a run saw: a digest for every source file it proved, and for every test
 * file both its digest and the source files it was seen to run.
 *
 * Comparing two of these is the whole decision mule makes.
 *
 * **The test's reach is recorded rather than hashed into a single number**, as
 * an earlier version of this did. A digest over the covering files answers
 * "has anything about them changed" and nothing else; the list answers which
 * files share a test, which is what makes it possible to notice that changing
 * one file unproved another. It is also what a later run needs in order to ask
 * the test runner for a subset.
 */
final readonly class Snapshot {

	/**
	 * @param array<string, string> $verified Project-relative source path to its digest, sorted.
	 * @param array<string, Touched> $tests Project-relative test path to what it is and runs, sorted.
	 * @param array<string, Unproven> $unproven Source paths the tests do not reach all of, sorted.
	 * @param array<string, string> $watched Everything else the project autoloads, sorted.
	 * @param array<string, string> $configuration What the verdicts were measured under, sorted.
	 * @param array<string, Reach> $coverage How much of each source file the tests reached, sorted.
	 * @param array<string, string> $engine The engine the verdicts were proven under, sorted.
	 */
	private function __construct(
		private array $verified,
		private array $tests,
		private array $unproven,
		private array $watched,
		private array $configuration,
		private array $coverage,
		private array $engine,
	) {}

	/**
	 * **The last two sections are disjoint by construction.** A path that
	 * declares what a verdict means is held as configuration and nothing else,
	 * so a store written before the two were told apart — which kept them
	 * together under `watched` — does not go on carrying `composer.json` in
	 * both once it is merged into.
	 *
	 * @param array<string, string> $verified
	 * @param array<string, Touched> $tests
	 * @param array<string, Unproven> $unproven
	 * @param array<string, string> $watched
	 * @param array<string, string> $configuration
	 * @param array<string, Reach> $coverage
	 * @param array<string, string> $engine
	 * @return self
	 */
	public static function of(
		array $verified,
		array $tests,
		array $unproven = [],
		array $watched = [],
		array $configuration = [],
		array $coverage = [],
		array $engine = [],
	): self {
		$watched = array_diff_key($watched, $configuration);

		ksort($verified);
		ksort($tests);
		ksort($unproven);
		ksort($watched);
		ksort($configuration);
		ksort($coverage);
		ksort($engine);

		return new self($verified, $tests, $unproven, $watched, $configuration, $coverage, $engine);
	}

	/**
	 * Every *file* this snapshot has a digest for: what a later run has to
	 * re-hash to know that nothing has moved.
	 *
	 * **The configuration and the engine are deliberately absent.** Everything
	 * here is a path that has to exist — it is re-hashed on every read and
	 * pruned when it goes — and neither of those two is a path. They are
	 * compared on their own, together, by {@see self::definition()}.
	 *
	 * @return array<string, string>
	 */
	public function digests(): array {
		$digests = $this->watched;

		foreach($this->verified as $path => $digest) {
			$digests[$path] = $digest;
		}

		foreach($this->unproven as $path => $entry) {
			$digests[$path] = $entry->digest;
		}

		foreach($this->tests as $path => $test) {
			$digests[$path] = $test->source;
		}

		return $digests;
	}

	/**
	 * Each proven file and the digest it was proven against.
	 *
	 * @return array<string, string>
	 */
	public function verified(): array {
		return $this->verified;
	}

	/**
	 * Each file the tests do not reach all of, as it was measured.
	 *
	 * @return array<string, Unproven>
	 */
	public function unproven(): array {
		return $this->unproven;
	}

	/**
	 * The files the tests do not reach all of, and where they stop.
	 *
	 * @return array<string, list<\Kristos80\Mule\Coverage\Unreached>>
	 */
	public function gaps(): array {
		$gaps = [];

		foreach($this->unproven as $path => $entry) {
			$gaps[$path] = $entry->unreached;
		}

		return $gaps;
	}

	/**
	 * The digest each unproven file was measured against.
	 *
	 * @return array<string, string>
	 */
	public function unprovenDigests(): array {
		$digests = [];

		foreach($this->unproven as $path => $entry) {
			$digests[$path] = $entry->digest;
		}

		return $digests;
	}

	/**
	 * Everything the project autoloads that the report never mentioned, with
	 * their digests.
	 *
	 * @return array<string, string>
	 */
	public function watched(): array {
		return $this->watched;
	}

	/**
	 * The files that declare what a verdict means, with their digests.
	 *
	 * @return array<string, string>
	 */
	public function configuration(): array {
		return $this->configuration;
	}

	/**
	 * How much of each source file the tests reached when it was measured.
	 *
	 * @return array<string, Reach>
	 */
	public function coverage(): array {
		return $this->coverage;
	}

	/**
	 * The engine the verdicts were proven under.
	 *
	 * **Deliberately absent from {@see self::digests()}.** Everything there is
	 * a file that has to exist: a path missing from the tree makes the store
	 * unusable, and one that is merely recorded is pruned when it goes. This is
	 * a fact about an installation rather than a path, so it is kept apart and
	 * compared on its own.
	 *
	 * @return array<string, string>
	 */
	public function engine(): array {
		return $this->engine;
	}

	/**
	 * How much of the whole of it the tests reached.
	 *
	 * **Summed from the files rather than recorded as a total**, so that a run
	 * which re-measured three files of four hundred reports the project's
	 * coverage and not those three files' — the store's entries for everything
	 * it did not look at are still the truth about them.
	 *
	 * @return Reach
	 */
	public function reach(): Reach {
		$reach = Reach::none();

		foreach($this->coverage as $one) {
			$reach = $reach->plus($one);
		}

		return $reach;
	}

	/**
	 * Every test recorded, with what it runs.
	 *
	 * @return array<string, Touched>
	 */
	public function tests(): array {
		return $this->tests;
	}

	/**
	 * @return list<string>
	 */
	public function paths(): array {
		return array_keys($this->verified);
	}

	/**
	 * @return int
	 */
	public function count(): int {
		return count($this->verified);
	}

	/**
	 * Of the paths given, the ones this snapshot holds a verdict for, in the
	 * order they were given.
	 *
	 * @param list<string> $paths
	 * @return list<string>
	 */
	public function knownOf(array $paths): array {
		$known = [];

		foreach($paths as $path) {
			if(isset($this->verified[$path])) {
				$known[] = $path;
			}
		}

		return $known;
	}

	/**
	 * The files in this snapshot whose verdict the stored one cannot vouch
	 * for: never recorded, recorded against different bytes, proven by a test
	 * that has changed, or proven by a test that runs something else that has.
	 *
	 * Direction matters. This snapshot is the universe — what the coverage
	 * report knows about — and the stored one only ever answers questions about
	 * it. A path the store holds and the report does not is a deleted or newly
	 * excluded file, and nothing needs mutating on its behalf.
	 *
	 * **Measured under a different configuration, or by a different engine,
	 * every verdict is stale** — see {@see self::supersedes()}.
	 *
	 * @param self $stored
	 * @return list<string>
	 */
	public function staleAgainst(self $stored): array {
		if($this->supersedes($stored)) {
			return $this->paths();
		}

		$moved = $this->moved($stored);
		$stale = [];

		foreach([...$this->changed($moved), ...$this->sharing($moved)] as $path) {
			$stale[$path] = $path;
		}

		ksort($stale);

		return array_keys($stale);
	}

	/**
	 * This snapshot with another's entries written over it, section by
	 * section.
	 *
	 * **Written over, never replaced — except where the newer snapshot speaks
	 * for the whole project.** A run that re-measured only part of it knows
	 * nothing about the rest, so its empty sections must not erase what an
	 * earlier run recorded. What it does know wins: a file it proved stops
	 * being unproven, and a file it could not prove stops being verified,
	 * because a path belongs to exactly one of the two and the newer answer is
	 * the true one.
	 *
	 * **A snapshot that redefines the gate replaces it outright**, because the
	 * verdicts under it are not old, they are answers to a different question.
	 * Merging them forward is how a store comes to claim more than anything
	 * ever proved: measured on a real project, where a gate change left 610
	 * verdicts standing of which one run had earned 221.
	 *
	 * **And what declares the gate is replaced rather than merged** whenever
	 * the newer snapshot declares anything at all. Merging it is how a record
	 * of something mule has stopped declaring survives forever — there is
	 * nothing to delete it, the comparison is for equality, and the store is
	 * unusable from then on with no way back. Measured the hard way: an
	 * `infection.json5` digest written by a version that read the file
	 * outlived the version that stopped, and bricked the cache of the project
	 * this tool was written for.
	 *
	 * @param self $other
	 * @return self
	 */
	public function merged(self $other): self {
		if($other->supersedes($this)) {
			return $other;
		}

		$verified = [...$this->verified, ...$other->verified];
		$unproven = [...$this->unproven, ...$other->unproven];

		foreach($other->verified as $path => $digest) {
			unset($unproven[$path]);
		}

		foreach($other->unproven as $path => $entry) {
			unset($verified[$path]);
		}

		return self::of(
			$verified,
			[...$this->tests, ...$other->tests],
			$unproven,
			[...$this->watched, ...$other->watched],
			self::later($this->configuration, $other->configuration),
			[...$this->coverage, ...$other->coverage],
			self::later($this->engine, $other->engine),
		);
	}

	/**
	 * This snapshot with the named paths gone from every section.
	 *
	 * **For paths that are not there any more.** A store is merged into rather
	 * than replaced, so an entry nobody mentions again survives — which is
	 * right for a run that only measured part of the project and wrong for a
	 * file that has been deleted. Left alone, that entry is a file the store
	 * records and the tree does not have, which is the one thing
	 * {@see Freshness} refuses to answer past: the fast path would be dead for
	 * good, and no amount of re-running would bring it back.
	 *
	 * @param list<string> $paths
	 * @return self
	 */
	public function without(array $paths): self {
		$verified = $this->verified;
		$tests = $this->tests;
		$unproven = $this->unproven;
		$watched = $this->watched;
		$configuration = $this->configuration;
		$coverage = $this->coverage;

		foreach($paths as $path) {
			unset(
				$verified[$path],
				$tests[$path],
				$unproven[$path],
				$watched[$path],
				$configuration[$path],
				$coverage[$path],
			);
		}

		return self::of($verified, $tests, $unproven, $watched, $configuration, $coverage, $this->engine);
	}

	/**
	 * Just the named files, plus everything that was observed rather than
	 * proven: the gaps, what is watched, how much of each was reached, and every
	 * test that runs any of it.
	 *
	 * **The tests of an unproven file are kept too.** A file nothing could
	 * prove is still carried forward, and dropping the test that runs it would
	 * leave the store describing a tree it has a hole in — which the next run
	 * reads as "a file I have never heard of" and refuses to answer from.
	 *
	 * @param list<string> $paths
	 * @return self
	 */
	public function only(array $paths): self {
		$verified = [];
		$wanted = [];

		foreach($paths as $path) {
			$verified[$path] = $this->verified[$path]
				?? throw new Failure("{$path} is not in this run's verdicts, so mule cannot bank it.");
			$wanted[] = $path;
		}

		foreach($this->unproven as $path => $entry) {
			$wanted[] = $path;
		}

		$kept = array_flip($wanted);

		return self::of(
			$verified,
			$this->testsRunning($kept),
			$this->unproven,
			$this->watched,
			$this->configuration,
			array_intersect_key($this->coverage, $kept),
			$this->engine,
		);
	}

	/**
	 * Whether this run was measured under a configuration the stored verdicts
	 * were not, which unproves all of them at once.
	 *
	 * **A verdict is only ever a verdict under some configuration.** Adding a
	 * mutator profile changes what "every mutant died" asserts, so the stored
	 * answer is not merely old, it is an answer to a different question — and
	 * none of the source digests move to say so. Measured before this existed:
	 * adding `@equal` to `infection.json5` left mule reporting "30 of 30
	 * verified, nothing to mutate" about a gate that had just been widened.
	 *
	 * **Everything, rather than working out what the change could reach.**
	 * Which mutants a newly enabled profile would add to which file is the
	 * engine's business, and guessing it wrong is a false verdict; re-proving a
	 * project after its gate is redefined is a thing somebody did on purpose.
	 *
	 * **The engine counts for the same reason.** Which mutants exist is the
	 * engine's business, so a verdict proven by one version is not a verdict
	 * under another. Measured on a real project: a patch-range bump left 610
	 * recorded verdicts standing and surfaced as a hundred and seventy-three
	 * survivors, with every byte of source, test and configuration unchanged.
	 *
	 * **A snapshot with neither recorded supersedes nothing.** That is a partial
	 * report, which is only ever read on a run whose store was usable — and a
	 * usable store has already had both compared.
	 *
	 * @param self $stored
	 * @return bool
	 */
	private function supersedes(self $stored): bool {
		$definition = $this->definition();

		return $definition !== [] && $definition !== $stored->definition();
	}

	/**
	 * Whichever of the two was recorded by a run that could say, newer first.
	 *
	 * **Replaced rather than merged.** Merging is how a record of something
	 * mule has stopped declaring survives forever: nothing deletes it, the
	 * comparison is for equality, and the store is unusable from then on with
	 * no way back.
	 *
	 * @param array<string, string> $stored
	 * @param array<string, string> $measured
	 * @return array<string, string>
	 */
	private static function later(array $stored, array $measured): array {
		return $measured === [] ? $stored : $measured;
	}

	/**
	 * Everything that decides what a verdict asserts, as one map: the files
	 * that declare the gate, and the engine that would enforce it.
	 *
	 * @return array<string, string>
	 */
	private function definition(): array {
		return [...$this->configuration, ...$this->engine];
	}

	/**
	 * Every path whose bytes differ from what was stored, or that was never
	 * stored at all, as a set.
	 *
	 * @param self $stored
	 * @return array<string, int> A set: only the keys mean anything.
	 */
	private function moved(self $stored): array {
		$moved = [];

		foreach($this->verified as $path => $digest) {
			if(($stored->verified[$path] ?? NULL) !== $digest) {
				$moved[] = $path;
			}
		}

		foreach($this->tests as $path => $test) {
			if(($stored->tests[$path]->source ?? NULL) !== $test->source) {
				$moved[] = $path;
			}
		}

		return array_flip($moved);
	}

	/**
	 * The proven files among those that moved.
	 *
	 * @param array<string, int> $moved
	 * @return list<string>
	 */
	private function changed(array $moved): array {
		$changed = [];

		foreach($this->verified as $path => $digest) {
			if(isset($moved[$path])) {
				$changed[] = $path;
			}
		}

		return $changed;
	}

	/**
	 * The proven files whose tests moved, or run something that did.
	 *
	 * @param array<string, int> $moved
	 * @return list<string>
	 */
	private function sharing(array $moved): array {
		$sharing = [];

		foreach($this->tests as $path => $test) {
			if(isset($moved[$path]) || $test->runsAnyOf($moved)) {
				foreach($this->knownOf($test->touches) as $touched) {
					$sharing[] = $touched;
				}
			}
		}

		return $sharing;
	}

	/**
	 * Every test that runs one of the paths named.
	 *
	 * @param array<string, int> $wanted
	 * @return array<string, Touched>
	 */
	private function testsRunning(array $wanted): array {
		$running = [];

		foreach($this->tests as $path => $test) {
			if($test->runsAnyOf($wanted)) {
				$running[$path] = $test;
			}
		}

		return $running;
	}
}
