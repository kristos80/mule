<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Engine\Gate;
use Kristos80\Mule\Engine\Outcome;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Cache\Footprint;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase {

	/**
	 * @param array<string, int> $stats
	 * @param array<string, list<string>> $sections Kind to the files mutants were filed against.
	 * @return Outcome
	 */
	/**
	 * Every gated file fully reached, so a test that cares about mutants says
	 * nothing about lines.
	 *
	 * @param list<string> $paths
	 * @return array<string, Reach>
	 */
	private function reached(array $paths): array {
		$reach = [];

		foreach($paths as $path) {
			$reach[$path] = new Reach(10, 10, TRUE);
		}

		return $reach;
	}

	/**
	 * @param array<string, int> $stats
	 * @param array<string, list<string>> $sections Kind to the files mutants were filed against.
	 * @return Outcome
	 */
	private function outcome(array $stats = [], array $sections = ["killed" => ["/p/src/A.php"]]): Outcome {
		$log = ["stats" => [
			"skippedCount" => 0,
			"timeOutCount" => 0,
			"syntaxErrorCount" => 0,
			"ignoredCount" => 0,
			"killedByStaticAnalysisCount" => 0,
			...$stats,
		]];

		foreach($sections as $kind => $files) {
			$log[$kind] = array_map(
				static fn(string $file): array => ["mutator" => ["originalFilePath" => $file]],
				$files,
			);
		}

		return Outcome::of($log, static fn(string $path): string => str_replace("/p/", "", $path));
	}

	public function testBanksAFileWhoseEveryMutantWasKilledByATest(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/A.php" => "key-a"],
			"chain",
			[],
			$this->reached(["src/A.php"]),
		);

		self::assertSame(["src/A.php"], array_keys($snapshot->verdicts));
		self::assertSame("key-a", $snapshot->verdicts["src/A.php"]->key);
		self::assertSame(1, $snapshot->verdicts["src/A.php"]->mutants);
	}

	/**
	 * Two files at once, one proven and one not, so the loop has to carry on
	 * past the first rather than stop at it.
	 *
	 * @return void
	 */
	public function testSortsASetOfFilesIntoProvenAndNot(): void {
		$outcome = $this->outcome(sections: [
			"killed" => ["/p/src/A.php", "/p/src/B.php"],
			"escaped" => ["/p/src/B.php"],
		]);

		$snapshot = new Gate()->proven(
			$outcome,
			["src/A.php" => "key-a", "src/B.php" => "key-b"],
			"chain",
			[],
			$this->reached(["src/A.php", "src/B.php"]),
		);

		self::assertSame(["src/A.php"], array_keys($snapshot->verdicts));
		self::assertSame(["src/B.php"], array_keys($snapshot->unproven));
	}

	public function testDoesNotBankAFileWithASurvivor(): void {
		$outcome = $this->outcome(sections: ["killed" => ["/p/src/A.php"], "escaped" => ["/p/src/A.php"]]);

		$snapshot = new Gate()->proven(
			$outcome,
			["src/A.php" => "key-a"],
			"chain",
			[],
			$this->reached(["src/A.php"]),
		);

		self::assertSame([], $snapshot->verdicts);
		self::assertSame(["src/A.php"], array_keys($snapshot->unproven));
	}

	/**
	 * The four statuses Infection scores as settled and mule cannot, each one an
	 * unverified mutant wearing the costume of a verified one.
	 *
	 * @return void
	 */
	public function testRefusesToBankARunCarryingUnverifiedMutants(): void {
		$caught = [];

		foreach([
			"skippedCount",
			"timeOutCount",
			"syntaxErrorCount",
			"ignoredCount",
			"killedByStaticAnalysisCount",
		] as $statistic) {
			try {
				new Gate()->proven(
					$this->outcome([$statistic => 1]),
					["src/A.php" => "key-a"],
					"chain",
					[],
					$this->reached(["src/A.php"]),
				);
			} catch(Failure $failure) {
				$caught[$statistic] = $failure->getMessage();
			}
		}

		self::assertSame([
			"skippedCount",
			"timeOutCount",
			"syntaxErrorCount",
			"ignoredCount",
			"killedByStaticAnalysisCount",
		], array_keys($caught));

		self::assertSame(
			"This run cannot be banked, because 1 mutants were generated and never executed;"
			. " they are in no section of the log and do not lower MSI (G2). Nothing was written.",
			reset($caught),
		);
	}

	public function testNamesEveryFaultRatherThanTheFirst(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage(
			"This run cannot be banked, because 2 mutants were generated and never executed;"
			. " they are in no section of the log and do not lower MSI (G2); and 3 mutants were"
			. " cut off before the tests could decide, which is an absence of evidence rather"
			. " than evidence of absence (G8). Nothing was written.",
		);

		new Gate()->proven(
			$this->outcome(["skippedCount" => 2, "timeOutCount" => 3]),
			["src/A.php" => "key-a"],
			"chain",
			[],
			$this->reached(["src/A.php"]),
		);
	}

	/**
	 * G1, and the measured failure behind it: stale coverage leaves a file's
	 * mutable lines looking unreached, so nothing is generated, the file vanishes
	 * from the report, and the run exits 0 having proven nothing about it.
	 *
	 * @return void
	 */
	public function testRefusesToBankAFileTheEngineFiledNothingAgainst(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage(
			"The engine filed no mutants against 1 file(s) it was asked to re-prove, each of which"
			. " has lines it could have mutated. Either their coverage is stale — a file whose"
			. " covered lines have moved generates nothing and disappears from the report — or they"
			. ' have nothing to mutate, in which case name them in "exclude". Nothing was written.'
			. "\n  src/Missing.php",
		);

		new Gate()->proven(
			$this->outcome(),
			["src/Missing.php" => "key-m"],
			"chain",
			[],
			$this->reached(["src/Missing.php"]),
		);
	}

	/**
	 * A mutant the engine scored as detected some other way is not a kill, so a
	 * file carrying one is not proven even though Infection's own MSI counts it.
	 *
	 * @return void
	 */
	public function testAFileDetectedOnlyByAnErrorIsNotProven(): void {
		$outcome = $this->outcome(sections: ["errored" => ["/p/src/A.php"]]);

		$snapshot = new Gate()->proven(
			$outcome,
			["src/A.php" => "key-a"],
			"chain",
			[],
			$this->reached(["src/A.php"]),
		);

		self::assertSame([], $snapshot->verdicts);
		self::assertSame(["src/A.php"], array_keys($snapshot->unproven));
	}

	public function testCarriesTheToolchainAndTheTestRecordThrough(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/A.php" => "key-a"],
			"chain",
			$tests = ["tests/ATest.php" => new Footprint("sig", ["src/A.php"])],
			$this->reached(["src/A.php"]),
		);

		self::assertSame("chain", $snapshot->toolchain);
		self::assertSame($tests, $snapshot->tests);
	}

	/**
	 * **A file the suite does not reach arrives already decided.** The engine files
	 * `uncovered` mutants for most of them, which would make the file unproven
	 * anyway — but an uncovered line whose statements are unmutable produces no
	 * mutant at all, so a file can reach MSI 100 % with part of it never run.
	 *
	 * It skips G1 too, because the reason it is unproven is already known and a
	 * zero-mutant message would name the wrong cause.
	 *
	 * @return void
	 */
	public function testAFileTheSuiteDoesNotReachIsNotBanked(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/A.php" => "key-a", "src/Bare.php" => "key-b"],
			"chain",
			[],
			[...$this->reached(["src/A.php"]), "src/Bare.php" => new Reach(20, 9, FALSE)],
		);

		self::assertSame(["src/A.php"], array_keys($snapshot->verdicts));
		self::assertSame(["src/Bare.php"], array_keys($snapshot->unproven));
		self::assertSame(
			"the suite reaches 9 of 20 line(s)",
			$snapshot->unproven["src/Bare.php"]->because(),
		);
	}

	/**
	 * **Only the files this run was asked about.** A caller may know of a gap in a
	 * file this run never examined, and recording that as a finding of *this* run
	 * would put a verdict's worth of weight on something nobody measured.
	 *
	 * @return void
	 */
	/**
	 * **Only the files this run was asked about.** A caller may know of a gap in a
	 * file this run never examined, and recording that as a finding of *this* run
	 * would put a verdict's worth of weight on something nobody measured.
	 *
	 * @return void
	 */
	public function testAGapInAFileThisRunDidNotExamineIsNotItsFinding(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/A.php" => "key-a"],
			"chain",
			[],
			[...$this->reached(["src/A.php"]), "src/Elsewhere.php" => new Reach(20, 0, FALSE)],
		);

		self::assertSame(["src/A.php"], array_keys($snapshot->verdicts));
		self::assertSame([], array_keys($snapshot->unproven));
	}

	public function testEveryFileTheSuiteDoesNotReachIsNamed(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/A.php" => "key-a", "src/Bare.php" => "key-b", "src/Barer.php" => "key-c"],
			"chain",
			[],
			[
				...$this->reached(["src/A.php"]),
				"src/Bare.php" => new Reach(2, 1, FALSE),
				"src/Barer.php" => new Reach(2, 0, FALSE),
			],
		);

		self::assertSame(["src/Bare.php", "src/Barer.php"], array_keys($snapshot->unproven));
	}

	/**
	 * **The reason is recorded, not just the name.** A file with three escapes of
	 * forty-seven and a file the suite reaches half of are both "not proven", and
	 * the difference decides whether somebody writes an assertion or writes a test.
	 *
	 * @return void
	 */
	public function testItRecordsWhyAFileIsNotProven(): void {
		$outcome = $this->outcome(sections: [
			"killed" => ["/p/src/A.php", "/p/src/A.php"],
			"escaped" => ["/p/src/A.php"],
			"uncovered" => ["/p/src/A.php"],
		]);

		$lapse = new Gate()->proven(
			$outcome,
			["src/A.php" => "key-a"],
			"chain",
			[],
			$this->reached(["src/A.php"]),
		)->unproven["src/A.php"];

		self::assertSame(4, $lapse->mutants);
		self::assertSame(2, $lapse->killed);
		self::assertSame(1, $lapse->escaped);
		self::assertSame(1, $lapse->uncovered);
		self::assertSame(
			"1 of 4 mutant(s) escaped and 1 were on lines nothing runs",
			$lapse->because(),
		);
	}

	/**
	 * **A file asked about and not measured is a caller bug rather than a
	 * verdict.** The same discipline as G1 from the other side: a run decides what
	 * to key and what to measure in one pass, so the two lists are the same list —
	 * and defaulting the figures would record a file as fully reached because
	 * nobody said otherwise.
	 *
	 * @return void
	 */
	public function testAFileAskedAboutAndNotMeasured(): void {
		$this->expectExceptionMessage(
			'mule asked the engine about "src/A.php" without recording how much of it the suite'
			. ' runs, so there is nothing to check the coverage floor against. Nothing was written.',
		);

		new Gate()->proven($this->outcome(), ["src/A.php" => "key-a"], "chain", [], []);
	}

	/**
	 * **Every offender at once.** One run should hand over the whole list: a guard
	 * that stopped at the first turned populating `exclude` into one run per file,
	 * and on a 613-file application that is a morning.
	 *
	 * @return void
	 */
	public function testItNamesEveryFileTheEngineFiledNothingAgainst(): void {
		$this->expectExceptionMessageMatches(
			'/no mutants against 2 file\(s\).*\n  src\/Missing\.php\n  src\/AlsoMissing\.php$/s',
		);

		new Gate()->proven(
			$this->outcome(),
			["src/Missing.php" => "key-m", "src/AlsoMissing.php" => "key-a"],
			"chain",
			[],
			$this->reached(["src/Missing.php", "src/AlsoMissing.php"]),
		);
	}

	/**
	 * **A file with no executable lines is not a G1 failure, and the parser says
	 * so.** An interface, or an enum of nothing but cases, has nothing a mutator
	 * could change — so it banks with `mutants: 0`, which is a claim rather than an
	 * absence. Measured on a 613-file application: 187 of its files are interfaces
	 * or enums, and naming every one in `exclude` would be bureaucracy rather than
	 * a decision anybody made.
	 *
	 * @return void
	 */
	public function testAFileWithNothingToMutateIsProvenRatherThanRefused(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/Thing.php" => "key-t"],
			"chain",
			[],
			["src/Thing.php" => new Reach(0, 0, TRUE)],
		);

		self::assertSame(["src/Thing.php"], array_keys($snapshot->verdicts));
		self::assertSame(0, $snapshot->verdicts["src/Thing.php"]->mutants);
	}

	/**
	 * **The guard keeps its teeth where they matter.** The line count comes from
	 * the file on disk, not from the coverage report — so a file whose coverage
	 * went stale still has executable lines and still fails.
	 *
	 * @return void
	 */
	public function testAFileWithLinesAndNoMutantsStillFails(): void {
		$this->expectExceptionMessage("no mutants against 1 file(s)");

		new Gate()->proven(
			$this->outcome(),
			["src/Stale.php" => "key-s"],
			"chain",
			[],
			["src/Stale.php" => new Reach(20, 20, TRUE)],
		);
	}

	/**
	 * And a file below the floor is reported as the coverage gap it is, rather than
	 * as a zero-mutant fault that would name the wrong cause.
	 *
	 * @return void
	 */
	public function testAFileBelowTheFloorIsAGapRatherThanAFault(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/Bare.php" => "key-b"],
			"chain",
			[],
			["src/Bare.php" => new Reach(20, 0, FALSE)],
		);

		self::assertSame(
			"the suite reaches 0 of 20 line(s)",
			$snapshot->unproven["src/Bare.php"]->because(),
		);
	}
}
