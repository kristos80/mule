<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Verdict;
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
	 * @param list<string> $uncovered Files the report does not cover to the floor.
	 * @return Snapshot
	 */
	public function proven(
		Outcome $outcome,
		array $keys,
		string $toolchain,
		array $tests,
		array $uncovered = [],
	): Snapshot {
		$this->assertNothingUnverified($outcome);

		$verdicts = [];
		$unproven = $this->decided($uncovered, $keys);

		foreach(array_diff_key($keys, array_flip($unproven)) as $path => $key) {
			$this->assertExamined($outcome, $path);

			if($outcome->mutants($path) === $outcome->count($path, "killed")) {
				$verdicts[$path] = new Verdict($key, $outcome->count($path, "killed"));

				continue;
			}

			$unproven[] = $path;
		}

		return new Snapshot($verdicts, $unproven, $tests, $toolchain);
	}

	/**
	 * The files that arrive already decided: the ones the suite does not reach,
	 * less any this run was not asked about.
	 *
	 * **Only the files keyed.** A caller may know about a gap in a file this run
	 * never examined, and recording that as a finding of *this* run would put a
	 * verdict's worth of weight on something nobody measured.
	 *
	 * @param list<string> $uncovered
	 * @param array<string, non-empty-string> $keys
	 * @return list<string>
	 */
	private function decided(array $uncovered, array $keys): array {
		$decided = [];

		foreach($uncovered as $path) {
			if(isset($keys[$path])) {
				$decided[] = $path;
			}
		}

		return $decided;
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
	 * A file that genuinely has nothing to mutate belongs in `exclude`, where
	 * saying so is a decision somebody made rather than a silence mule guessed at.
	 *
	 * @param Outcome $outcome
	 * @param string $path
	 * @return void
	 */
	private function assertExamined(Outcome $outcome, string $path): void {
		if($outcome->mutants($path) === 0) {
			throw new Failure(
				"The engine filed no mutants against \"{$path}\", which it was asked to re-prove."
				. " Either its coverage is stale — a file whose covered lines have moved generates"
				. " nothing and disappears from the report — or it has nothing to mutate, in which"
				. " case name it in \"exclude\". Nothing was written.",
			);
		}
	}
}
