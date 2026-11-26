<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

use Kristos80\Mule\Engine\Outcome;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function log(array $overrides = []): array {
		return [
			"stats" => ["totalMutantsCount" => 4, "skippedCount" => 0, "msi" => 100],
			"killed" => [
				["mutator" => ["originalFilePath" => "/p/src/Zzz.php"]],
				["mutator" => ["originalFilePath" => "/p/src/Aaa.php"]],
				["mutator" => ["originalFilePath" => "/p/src/Aaa.php"]],
			],
			"escaped" => [["mutator" => ["originalFilePath" => "/p/src/Aaa.php"]]],
			...$overrides,
		];
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return Outcome
	 */
	private function outcome(array $overrides = []): Outcome {
		return Outcome::of(
			$this->log($overrides),
			static fn(string $path): string => str_replace("/p/", "", $path),
		);
	}

	public function testCountsMutantsPerFileAndPerKind(): void {
		$outcome = $this->outcome();

		self::assertSame(2, $outcome->count("src/Aaa.php", "killed"));
		self::assertSame(1, $outcome->count("src/Aaa.php", "escaped"));
		self::assertSame(1, $outcome->count("src/Zzz.php", "killed"));
	}

	public function testCountsEveryMutantFiledAgainstAFile(): void {
		self::assertSame(3, $this->outcome()->mutants("src/Aaa.php"));
	}

	public function testGivesZeroForAKindTheFileHasNone(): void {
		self::assertSame(0, $this->outcome()->count("src/Zzz.php", "escaped"));
	}

	public function testGivesZeroForAFileTheEngineNeverMentioned(): void {
		self::assertSame(0, $this->outcome()->mutants("src/Never.php"));
	}

	public function testNamesTheFilesItHeardAbout(): void {
		self::assertSame(["src/Aaa.php", "src/Zzz.php"], $this->outcome()->files());
	}

	public function testTranslatesTheLoggedPathsIntoTheStoresSpelling(): void {
		self::assertSame(["src/Aaa.php", "src/Zzz.php"], $this->outcome()->files());
	}

	/**
	 * M6: `uncovered`, not `notCovered`. Reading the wrong name would make every
	 * uncovered mutant invisible.
	 *
	 * @return void
	 */
	public function testReadsTheUncoveredSectionUnderTheNameTheLogUses(): void {
		$outcome = $this->outcome(["uncovered" => [["mutator" => ["originalFilePath" => "/p/src/Aaa.php"]]]]);

		self::assertSame(1, $outcome->count("src/Aaa.php", "uncovered"));
	}

	public function testReadsEveryKindTheLogCanFileAMutantUnder(): void {
		$sections = [];

		foreach(Outcome::KINDS as $kind) {
			$sections[$kind] = [["mutator" => ["originalFilePath" => "/p/src/A.php"]]];
		}

		$outcome = $this->outcome($sections);

		self::assertSame(count(Outcome::KINDS), $outcome->mutants("src/A.php"));
	}

	/**
	 * Read rather than derived: a skipped mutant is in no section, so a count
	 * taken from the sections cannot see one.
	 *
	 * @return void
	 */
	public function testReadsAStatisticTheSectionsCannotShow(): void {
		$outcome = $this->outcome(["stats" => ["skippedCount" => 7]]);

		self::assertSame(7, $outcome->statistic("skippedCount"));
	}

	public function testRefusesAStatisticTheLogDoesNotCarry(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The engine\'s log gives no "inventedCount".');

		$this->outcome()->statistic("inventedCount");
	}

	public function testRefusesSomethingThatIsNotALog(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("The engine's log must be an array, string given.");

		Outcome::of("nonsense", static fn(string $path): string => $path);
	}

	/**
	 * Without statistics nothing about the run can be checked, which means the
	 * guards cannot run — so this is a refusal rather than an empty set.
	 *
	 * @return void
	 */
	public function testRefusesALogWithNoStatistics(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("The engine's log carries no statistics, so nothing about it can be checked.");

		Outcome::of(["killed" => []], static fn(string $path): string => $path);
	}

	public function testRefusesASectionThatIsNotAList(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The engine\'s log gives "killed" as string.');

		$this->outcome(["killed" => "one"]);
	}

	public function testRefusesAMutantFiledAgainstNoFile(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The engine filed a "killed" mutant against no file.');

		$this->outcome(["killed" => [["mutator" => []]]]);
	}

	public function testRefusesAMutantWithNoMutator(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The engine filed a "escaped" mutant against no file.');

		$this->outcome(["escaped" => [["diff" => "..."]]]);
	}

	/**
	 * A statistic that is not a number tells mule nothing, and a guard reading
	 * one would compare against rubbish — so it is dropped, and asking for it
	 * then fails loudly.
	 *
	 * @return void
	 */
	public function testKeepsOnlyStatisticsThatAreNumbers(): void {
		$outcome = $this->outcome(["stats" => ["skippedCount" => 0, "msi" => "100%"]]);

		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The engine\'s log gives no "msi".');

		$outcome->statistic("msi");
	}
}
