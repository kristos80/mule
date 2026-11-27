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
	 * What each test file was seen to execute, keyed by project-relative path.
	 *
	 * **This is the half that makes the cheap question cheap.** Which files are
	 * stale was otherwise knowable only by generating a coverage report, which
	 * made the cheap question depend on the expensive answer — a run had to spend
	 * the whole coverage pass to discover it had nothing to do. Recording what
	 * each test runs turns the same question into a few hundred digests.
	 *
	 * The covering tests of a file, and therefore everything its verdict rests
	 * on, are derivable from this alone: a test covers `F` when `F` is among what
	 * it executed.
	 *
	 * **Each record carries the bytes it was measured at**, because a test that
	 * covers nothing has its digest in no file's key and would otherwise be a file
	 * mule has heard of and recorded nothing checkable about (`Footprint`).
	 *
	 * @var array<string, Footprint>
	 */
	public array $tests;

	/**
	 * **Canonical on the way in, once.** The store is committed, so two machines
	 * that proved the same files have to write the same bytes — and the order
	 * verdicts arrive in is the order a coverage report or a cache happened to
	 * list them. Sorting here rather than at each use leaves one place to get it
	 * right and no second sort to disagree with.
	 *
	 * @param array<string, Verdict> $verdicts
	 * @param list<string> $unproven
	 * @param array<string, Footprint> $tests
	 * @param non-empty-string $toolchain The environment every verdict here was proven under.
	 */
	public function __construct(
		array $verdicts,
		array $unproven,
		array $tests,
		public string $toolchain,
	) {
		ksort($verdicts);
		ksort($tests);

		// `sort()` reindexes, so wrapping this in `array_values()` would be an
		// operation with no result behind it — and the gate said so.
		$unique = array_unique($unproven);
		sort($unique);

		$this->verdicts = $verdicts;
		$this->unproven = $unique;
		$this->tests = $tests;
	}

	/**
	 * The test files whose coverage includes a given source file.
	 *
	 * @param string $file
	 * @return list<string>
	 */
	public function coveredBy(string $file): array {
		$covering = [];

		foreach($this->tests as $test => $footprint) {
			if($footprint->covers($file)) {
				$covering[] = $test;
			}
		}

		return $covering;
	}

	/**
	 * Everything a verdict for a file rests on besides itself: the union of what
	 * its covering tests execute.
	 *
	 * **One rule, two sources.** `Runner\Run` builds a snapshot out of a fresh
	 * coverage pass and asks it the same question; this one answers from the store.
	 * That is deliberate and load-bearing: a run that keyed a file differently from
	 * the way the store re-keys it would bank verdicts no later run could ever
	 * match, and the cache would simply never hit — silently, with every run still
	 * correct and still as slow as the first.
	 *
	 * @param string $file
	 * @return list<string>
	 */
	public function dependenciesOf(string $file): array {
		$deps = [];

		foreach($this->coveredBy($file) as $test) {
			foreach($this->tests[$test]->touches as $touched) {
				$deps[] = $touched;
			}
		}

		$unique = array_unique($deps);
		sort($unique);

		return $unique;
	}

	/**
	 * Every path the store has heard of.
	 *
	 * **What it is for is the file it has *not* heard of.** A test that has
	 * appeared since the last run may cover anything, so its effect on a key
	 * cannot be worked out from digests — and a source file nobody recorded is a
	 * file no verdict covers. Either one means the cheap question cannot be
	 * answered and a coverage run is owed (G9).
	 *
	 * @return list<string>
	 */
	public function known(): array {
		$known = [...array_keys($this->verdicts), ...$this->unproven, ...array_keys($this->tests)];

		foreach($this->tests as $footprint) {
			foreach($footprint->touches as $touched) {
				$known[] = $touched;
			}
		}

		$unique = array_unique($known);
		sort($unique);

		return $unique;
	}

	/**
	 * The test files whose recorded bytes are not the bytes on disk.
	 *
	 * **The half of the cheap question that keys cannot answer.** A test file's
	 * digest reaches a key only through the files it covers, so a test that covers
	 * nothing has its bytes in no key — and editing it to start covering something
	 * moves nothing. Asking the record directly is what notices.
	 *
	 * @param array<string, non-empty-string> $digests
	 * @return list<string>
	 */
	public function unmeasured(array $digests): array {
		$unmeasured = [];

		foreach($this->tests as $test => $footprint) {
			if(isset($digests[$test]) && !$footprint->describes($digests, $test)) {
				$unmeasured[] = $test;
			}
		}

		return $unmeasured;
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

		// **The test record replaces rather than merges, and it is the one section
		// that does.** A run's verdicts are only ever about the files it examined,
		// but its record of what each test executes is the whole current picture:
		// a warm run starts from what the store said and writes the re-measured
		// test over it. Merging the old map back in would resurrect a test file
		// that has since been deleted — and a deleted test that keeps appearing
		// among a file's covering tests gives that file a key nothing can ever
		// reproduce, so it reads as outstanding on every run from then on.
		return new self(
			$verdicts,
			$this->mergedUnproven($newer, $verdicts),
			$newer->tests,
			$this->toolchain,
		);
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
