<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

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
	 * @param array<string, string> $watched Everything else the project autoloads, and its own config, sorted.
	 */
	private function __construct(
		private array $verified,
		private array $tests,
		private array $unproven,
		private array $watched,
	) {}

	/**
	 * @param array<string, string> $verified
	 * @param array<string, Touched> $tests
	 * @param array<string, Unproven> $unproven
	 * @param array<string, string> $watched
	 * @return self
	 */
	public static function of(array $verified, array $tests, array $unproven = [], array $watched = []): self {
		ksort($verified);
		ksort($tests);
		ksort($unproven);
		ksort($watched);

		return new self($verified, $tests, $unproven, $watched);
	}

	/**
	 * Everything this snapshot has a digest for, whatever section it sits in:
	 * what a later run has to re-hash to know that nothing has moved.
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
	 * Everything the project autoloads that the report never mentioned, and
	 * mule's own configuration, with their digests.
	 *
	 * @return array<string, string>
	 */
	public function watched(): array {
		return $this->watched;
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
	 * Whether every test in it was credited with exactly one file.
	 *
	 * **A report like this cannot describe a dependency.** Changing one file
	 * can never mark another stale, because nothing in the data says any two
	 * are connected — which is what `#[CoversClass]` does to a coverage
	 * report. It is worth saying out loud, because the narrowing then looks
	 * exactly as trustworthy as it would on a report that had measured
	 * everything.
	 *
	 * Fewer than two tests is not evidence of anything, so it is not reported.
	 *
	 * @return bool
	 */
	public function isAttributionBound(): bool {
		if(count($this->tests) < 2) {
			return FALSE;
		}

		foreach($this->tests as $test) {
			if(count($test->touches) !== 1) {
				return FALSE;
			}
		}

		return TRUE;
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
	 * @param self $stored
	 * @return list<string>
	 */
	public function staleAgainst(self $stored): array {
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
	 * **Written over, never replaced.** A run that re-measured only part of
	 * the project knows nothing about the rest, so its empty sections must not
	 * erase what an earlier run recorded. What it does know wins: a file it
	 * proved stops being unproven, and a file it could not prove stops being
	 * verified, because a path belongs to exactly one of the two and the
	 * newer answer is the true one.
	 *
	 * @param self $other
	 * @return self
	 */
	public function merged(self $other): self {
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
		);
	}

	/**
	 * Just the named files, plus everything that was observed rather than
	 * proven: the gaps, what is watched, and every test that runs any of it.
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

		return self::of($verified, $this->testsRunning(array_flip($wanted)), $this->unproven, $this->watched);
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
