<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

use Kristos80\Mule\Engine\Outcome;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutcomeTest extends TestCase {

	public function testVouchesForAFileWhoseEveryMutantTheEngineCounted(): void {
		self::assertSame(["/project/src/A.php"], Outcome::provenIn(self::log([
			"killed" => ["/project/src/A.php", "/project/src/A.php"],
		])));
	}

	public function testVouchesForNoFileAMutantSurvivedIn(): void {
		self::assertSame(["/project/src/A.php"], Outcome::provenIn(self::log([
			"killed" => ["/project/src/A.php", "/project/src/B.php"],
			"escaped" => ["/project/src/B.php"],
		])));
	}

	/**
	 * The engine counts a mutant that crashed, or that the clock killed,
	 * towards the score exactly as it counts one an assertion killed — so
	 * refusing to record those files would refuse ones the engine was satisfied
	 * with.
	 *
	 * @return void
	 */
	public function testCountsEveryWayTheEngineCallsAMutantDead(): void {
		self::assertSame([
			"/project/src/A.php",
			"/project/src/B.php",
			"/project/src/C.php",
			"/project/src/D.php",
		], Outcome::provenIn(self::log([
			"killedByStaticAnalysis" => ["/project/src/A.php"],
			"errored" => ["/project/src/B.php"],
			"syntaxErrors" => ["/project/src/C.php"],
			"timeouted" => ["/project/src/D.php"],
		])));
	}

	public function testVouchesForNoFileWithAMutantNothingCovered(): void {
		self::assertSame([], Outcome::provenIn(self::log([
			"killed" => ["/project/src/A.php"],
			"uncovered" => ["/project/src/A.php"],
		])));
	}

	public function testVouchesForNoFileWithAMutantNobodyRan(): void {
		self::assertSame([], Outcome::provenIn(self::log([
			"killed" => ["/project/src/A.php"],
			"ignored" => ["/project/src/A.php"],
		])));
	}

	/**
	 * The case positive evidence exists for: an engine that died halfway names
	 * neither the files it never reached nor a survivor in them, and taking the
	 * scope minus the blame would record verdicts for files nothing mutated.
	 *
	 * @return void
	 */
	public function testVouchesForNothingAtAllWhenTheLogNamesNoKilledMutant(): void {
		self::assertSame([], Outcome::provenIn(self::log(["escaped" => []])));
	}

	public function testVouchesForNothingWhenThereIsNoLogToRead(): void {
		self::assertSame([], Outcome::provenIn("{}"));
	}

	public function testAnswersInPathOrderWhateverOrderTheEngineReportedIn(): void {
		self::assertSame(["/project/src/A.php", "/project/src/Z.php"], Outcome::provenIn(self::log([
			"killed" => ["/project/src/Z.php", "/project/src/A.php"],
		])));
	}

	public function testRefusesALogItCannotRead(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read the engine's log");

		Outcome::provenIn("{not json");
	}

	public function testRefusesAMutantThatNamesNoFile(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The originalFilePath in the engine's log killed mutator must be a non-empty string.");

		Outcome::provenIn('{"killed": [{"mutator": {}}]}');
	}

	/**
	 * @param array<string, list<string>> $buckets Bucket name to the paths its mutants sit in.
	 * @return string
	 */
	private static function log(array $buckets): string {
		$log = [];

		foreach($buckets as $bucket => $paths) {
			$log[$bucket] = array_map(
				static fn(string $path): array => ["mutator" => ["originalFilePath" => $path]],
				$paths,
			);
		}

		return json_encode($log, JSON_THROW_ON_ERROR);
	}
}
