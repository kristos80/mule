<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Doctor;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Doctor\Diagnosis;
use Kristos80\Mule\Doctor\Spread;
use Kristos80\Mule\Doctor\Tier;
use PHPUnit\Framework\TestCase;

final class DiagnosisTest extends TestCase {

	/**
	 * @param array<string, Reach> $reach
	 * @return Diagnosis
	 */
	private function diagnosis(array $reach = []): Diagnosis {
		// Listed narrowest first, because a report's order is whichever one it
		// happened to produce and the widest are what somebody has to see.
		$tests = [
			"tests/SilentTest.php" => new Footprint("sig", []),
			"tests/NarrowTest.php" => new Footprint("sig", ["src/C.php"]),
			"tests/WideTest.php" => new Footprint("sig", ["src/A.php", "src/B.php", "src/Hub.php"]),
		];
		$gated = ["src/A.php", "src/B.php", "src/C.php", "src/Hub.php"];
		$spread = Spread::of($tests, $gated);

		return new Diagnosis($spread, Tier::of($spread), $tests, ["src/Gen.php"], [], $reach);
	}

	/**
	 * **Naming the files is a symptom; naming the tests is the cause.** A test that
	 * executes a large share of the project puts every file it touches into every
	 * other one's closure, so a dense project's remedy starts here.
	 *
	 * @return void
	 */
	public function testTheWidestFootprintsComeFirst(): void {
		self::assertSame(
			["tests/WideTest.php" => 3, "tests/NarrowTest.php" => 1],
			$this->diagnosis()->footprints(2),
		);
	}

	public function testATestThatRunsNothingIsStillCounted(): void {
		self::assertSame(0, $this->diagnosis()->footprints(3)["tests/SilentTest.php"]);
	}

	/**
	 * Worth knowing before a single mutant runs, because mule will report every one
	 * of them as unproven — and an uncovered line whose statements happen to be
	 * unmutable produces no mutant at all, so the score would not have said so.
	 *
	 * @return void
	 */
	public function testOnlyTheFilesTheSuiteDoesNotReachAreNamed(): void {
		$diagnosis = $this->diagnosis([
			"src/A.php" => new Reach(10, 10, TRUE),
			"src/B.php" => new Reach(20, 9, FALSE),
			"src/C.php" => new Reach(4, 0, FALSE),
		]);

		self::assertSame(["src/B.php", "src/C.php"], array_keys($diagnosis->unreached()));
		self::assertSame(9, $diagnosis->unreached()["src/B.php"]->executed);
	}

	public function testAProjectTheSuiteReachesEntirely(): void {
		self::assertSame([], $this->diagnosis(["src/A.php" => new Reach(10, 10, TRUE)])->unreached());
	}
}
