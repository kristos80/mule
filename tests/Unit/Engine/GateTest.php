<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

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
		$snapshot = new Gate()->proven($this->outcome(), ["src/A.php" => "key-a"], "chain", []);

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
		);

		self::assertSame(["src/A.php"], array_keys($snapshot->verdicts));
		self::assertSame(["src/B.php"], $snapshot->unproven);
	}

	public function testDoesNotBankAFileWithASurvivor(): void {
		$outcome = $this->outcome(sections: ["killed" => ["/p/src/A.php"], "escaped" => ["/p/src/A.php"]]);

		$snapshot = new Gate()->proven($outcome, ["src/A.php" => "key-a"], "chain", []);

		self::assertSame([], $snapshot->verdicts);
		self::assertSame(["src/A.php"], $snapshot->unproven);
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
				new Gate()->proven($this->outcome([$statistic => 1]), ["src/A.php" => "key-a"], "chain", []);
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
			'The engine filed no mutants against "src/Missing.php", which it was asked to re-prove.'
			. " Either its coverage is stale — a file whose covered lines have moved generates"
			. " nothing and disappears from the report — or it has nothing to mutate, in which"
			. ' case name it in "exclude". Nothing was written.',
		);

		new Gate()->proven($this->outcome(), ["src/Missing.php" => "key-m"], "chain", []);
	}

	/**
	 * A mutant the engine scored as detected some other way is not a kill, so a
	 * file carrying one is not proven even though Infection's own MSI counts it.
	 *
	 * @return void
	 */
	public function testAFileDetectedOnlyByAnErrorIsNotProven(): void {
		$outcome = $this->outcome(sections: ["errored" => ["/p/src/A.php"]]);

		$snapshot = new Gate()->proven($outcome, ["src/A.php" => "key-a"], "chain", []);

		self::assertSame([], $snapshot->verdicts);
		self::assertSame(["src/A.php"], $snapshot->unproven);
	}

	public function testCarriesTheToolchainAndTheTestRecordThrough(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/A.php" => "key-a"],
			"chain",
			$tests = ["tests/ATest.php" => new Footprint("sig", ["src/A.php"])],
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
			["src/Bare.php"],
		);

		self::assertSame(["src/A.php"], array_keys($snapshot->verdicts));
		self::assertSame(["src/Bare.php"], $snapshot->unproven);
	}

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
			["src/Elsewhere.php"],
		);

		self::assertSame(["src/A.php"], array_keys($snapshot->verdicts));
		self::assertSame([], $snapshot->unproven);
	}

	public function testEveryFileTheSuiteDoesNotReachIsNamed(): void {
		$snapshot = new Gate()->proven(
			$this->outcome(),
			["src/A.php" => "key-a", "src/Bare.php" => "key-b", "src/Barer.php" => "key-c"],
			"chain",
			[],
			["src/Bare.php", "src/Barer.php"],
		);

		self::assertSame(["src/Bare.php", "src/Barer.php"], $snapshot->unproven);
	}
}
