<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Cache\Lapse;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Verdict;
use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Failure;
use function implode;

/**
 * Whether a run's findings may be banked, and what of them.
 *
 * **This is where "detected by Infection" stops meaning "proven to mule".** The
 * engine counts a timed-out mutant, an errored one and one that will not parse as
 * kills, drops skipped and ignored mutants out of its denominator, and treats
 * `killedByStaticAnalysis` — which means the tests *failed* and a type checker
 * caught it afterwards — as a success (M14, M15). Every one of those is an
 * unverified mutant wearing the costume of a verified one.
 *
 * So the guards are assertions about the run as a whole, and only then is
 * anything written down.
 */
final readonly class Gate {

	/**
	 * Statistics that must be zero for a run to mean what it appears to mean,
	 * and why each one matters if it is not.
	 */
	private const array FORBIDDEN = [
		"skippedCount" => "mutants were generated and never executed; they are in no section of the log and do not lower MSI (G2)",
		"timeOutCount" => "mutants were cut off before the tests could decide, which is an absence of evidence rather than evidence of absence (G8)",
		"syntaxErrorCount" => "mutants did not parse, which is a broken mutator rather than a detection (G8)",
		"ignoredCount" => "mutants were ignored, and an ignored mutant leaves the denominator exactly as a skipped one does (G8)",
		"killedByStaticAnalysisCount" => "mutants escaped the tests and were caught by a type checker afterwards, which is the test gap mule exists to surface (G8, M15)",
	];

	/**
	 * What a run established, ready to merge into the store.
	 *
	 * **The keys are given, not invented.** A verdict is only worth anything
	 * because its key is taken over the digests the run actually measured, so the
	 * caller that did the measuring hands them over. The files asked about are
	 * exactly the ones keyed: a file with no key was not re-proven, and a key with
	 * no file is a caller bug rather than a verdict.
	 *
	 * **A file the suite does not reach arrives here already decided.** The engine
	 * files `uncovered` mutants for most of them, which would make the file
	 * unproven anyway — but an uncovered line whose statements are unmutable
	 * produces no mutant at all, so a file can reach MSI 100 % with part of it
	 * never run (`Coverage\Soundness`). Those skip G1, because the reason they are
	 * unproven is already known and a zero-mutant message would name the wrong
	 * cause.
	 *
	 * @param Outcome $outcome
	 * @param array<string, non-empty-string> $keys The files re-proven, and what each one keys to now.
	 * @param non-empty-string $toolchain
	 * @param array<string, Footprint> $tests What each test file was seen to run.
	 * @param array<string, Reach> $reach How much of each file the suite runs.
	 * @param list<string> $mutantFree The keyed files declared to have nothing a mutator could change.
	 * @return Snapshot
	 */
	public function proven(
		Outcome $outcome,
		array $keys,
		string $toolchain,
		array $tests,
		array $reach,
		array $mutantFree = [],
	): Snapshot {
		$this->assertNothingUnverified($outcome);
		$this->assertEverythingExamined($outcome, $keys, $reach, $mutantFree);
		$this->assertStillMutantFree($outcome, $mutantFree);

		$verdicts = [];
		$unproven = [];

		foreach($keys as $path => $key) {
			$lapse = $this->lapse($outcome, $path, $reach[$path]);

			if($lapse === NULL) {
				$verdicts[$path] = new Verdict($key, $outcome->count($path, "killed"));

				continue;
			}

			$unproven[$path] = $lapse;
		}

		return new Snapshot($verdicts, $unproven, $tests, $toolchain);
	}

	/**
	 * **A file asked about and not measured is a caller bug rather than a
	 * verdict.** The same discipline as G1 from the other side: a run decides what
	 * to key and what to measure in one pass, so the two lists are the same list —
	 * and defaulting the figures instead would record a file as fully reached
	 * because nobody said otherwise.
	 *
	 * @param array<string, Reach> $reach
	 * @param string $path
	 * @return void
	 */
	private function assertMeasured(array $reach, string $path): void {
		if(!isset($reach[$path])) {
			throw new Failure(
				"mule asked the engine about \"{$path}\" without recording how much of it the suite"
				. " runs, so there is nothing to check the coverage floor against. Nothing was"
				. " written.",
			);
		}
	}

	/**
	 * Why a file is not proven, or `NULL` if it is.
	 *
	 * @param Outcome $outcome
	 * @param string $path
	 * @param Reach $reach
	 * @return Lapse|null
	 */
	private function lapse(Outcome $outcome, string $path, Reach $reach): ?Lapse {
		if($reach->meets && $outcome->mutants($path) === $outcome->count($path, "killed")) {
			return NULL;
		}

		return new Lapse(
			$outcome->mutants($path),
			$outcome->count($path, "killed"),
			$outcome->count($path, "escaped"),
			$outcome->count($path, "uncovered"),
			$reach->executable,
			$reach->executed,
		);
	}

	/**
	 * **The run-wide guards, checked before anything is banked.** Each of these
	 * counts mutants the engine scored as settled and mule cannot: a verdict
	 * built on top of one would be a claim nobody earned.
	 *
	 * @param Outcome $outcome
	 * @return void
	 */
	private function assertNothingUnverified(Outcome $outcome): void {
		$faults = [];

		foreach(self::FORBIDDEN as $statistic => $why) {
			$count = $outcome->statistic($statistic);

			if($count !== 0) {
				$faults[] = "{$count} {$why}";
			}
		}

		if($faults !== []) {
			throw new Failure(
				"This run cannot be banked, because " . implode("; and ", $faults)
				. ". Nothing was written.",
			);
		}
	}

	/**
	 * **G1.** A file the engine was asked about and filed nothing against is the
	 * measured failure: stale coverage leaves its mutable lines looking unreached,
	 * so no mutants are generated, the file vanishes from the report, and the run
	 * exits 0 having proven nothing about it.
	 *
	 * **A file with no executable lines is not that, and the parser says so.** An
	 * interface, or an enum of nothing but cases, has nothing a mutator could
	 * change — so it banks with `mutants: 0`, which `Verdict` has always described
	 * as a claim rather than an absence. The guard keeps its teeth exactly where
	 * they matter: the line count comes from the file on disk, not from the
	 * coverage report, so a file whose *coverage* went stale still has executable
	 * lines and still fails. Measured on a 613-file application: 187 of its files
	 * are interfaces or enums, and naming every one of them in `exclude` would be
	 * bureaucracy rather than a decision anybody made.
	 *
	 * **A file below the coverage floor is not that either.** The engine files
	 * `uncovered` mutants for most of them, but an uncovered line whose statements
	 * are unmutable produces none — so the honest answer is "the suite reaches 9 of
	 * 20 lines", which `Coverage\Soundness` already supplies, and a zero-mutant
	 * message would name the wrong cause.
	 *
	 * **Every offender at once.** One run should hand over the whole list: a guard
	 * that stops at the first turns populating `exclude` into one run per file.
	 *
	 * **A file declared `mutant-free` is exempt**, because that declaration is the
	 * decision this guard asks for — and `assertStillMutantFree()` checks it from
	 * the other side.
	 *
	 * @param Outcome $outcome
	 * @param array<string, non-empty-string> $keys
	 * @param array<string, Reach> $reach
	 * @param list<string> $mutantFree
	 * @return void
	 */
	private function assertEverythingExamined(
		Outcome $outcome,
		array $keys,
		array $reach,
		array $mutantFree,
	): void {
		$silent = [];

		foreach(array_keys($keys) as $path) {
			$this->assertMeasured($reach, $path);

			if($this->unexplained($outcome, $reach[$path], $path, $mutantFree)) {
				$silent[] = $path;
			}
		}

		if($silent !== []) {
			throw new Failure(
				"The engine filed no mutants against " . count($silent) . " file(s) it was asked to"
				. " re-prove, each of which has lines it could have mutated. Either their coverage"
				. " is stale — a file whose covered lines have moved generates nothing and"
				. " disappears from the report — or they have nothing a mutator could change, in"
				. " which case name them in \"mutant-free\", where they stay gated and mule tells"
				. " you if that ever changes. Nothing was written.\n  " . implode("\n  ", $silent),
			);
		}
	}

	/**
	 * @param Outcome $outcome
	 * @param Reach $reach
	 * @param string $path
	 * @param list<string> $mutantFree
	 * @return bool
	 */
	private function unexplained(Outcome $outcome, Reach $reach, string $path, array $mutantFree): bool {
		if(in_array($path, $mutantFree, TRUE)) {
			return FALSE;
		}

		return $reach->executable > 0 && $reach->meets && $outcome->mutants($path) === 0;
	}

	/**
	 * **The other half of `mutant-free`, and the reason it is a declaration rather
	 * than an exclusion.** The list is resolved against the files actually keyed
	 * before it gets here (`Runner\Run`), so a directory entry is checked as the
	 * files under it rather than as a path the engine has never heard of.
	 * A value object of nothing but promoted properties
	 * produces no mutants today; the day it grows a method it produces some, and a
	 * file taken out of the gate would never have said so. So the claim is checked
	 * every run, and a file that stops being inert is named.
	 *
	 * @param Outcome $outcome
	 * @param list<string> $mutantFree
	 * @return void
	 */
	private function assertStillMutantFree(Outcome $outcome, array $mutantFree): void {
		$grown = [];

		foreach($mutantFree as $path) {
			if($outcome->mutants($path) > 0) {
				$grown[] = "{$path} ({$outcome->mutants($path)})";
			}
		}

		if($grown !== []) {
			throw new Failure(
				count($grown) . " file(s) named in \"mutant-free\" now have mutants, so the claim"
				. " that there is nothing in them to verify has stopped being true. Take them out"
				. " of the list and let the gate prove them. Nothing was written.\n  "
				. implode("\n  ", $grown),
			);
		}
	}
}
