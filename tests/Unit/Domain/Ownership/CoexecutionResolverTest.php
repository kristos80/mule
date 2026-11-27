<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Domain\Ownership;

use Kristos80\Mule\Domain\Ownership\CoexecutionResolver;
use Kristos80\Mule\Domain\Ownership\OwnershipMap;
use PHPUnit\Framework\TestCase;

final class CoexecutionResolverTest extends TestCase {

	private CoexecutionResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new CoexecutionResolver();
	}

	/**
	 * The case the one-hop term exists for. `NormalizedOrderMapper` is not a
	 * collaborator of the unit under test and is injected nowhere; a test-support
	 * factory calls it to build a fixture. Its behaviour still decides whether a
	 * mutant of the unit dies, so it has to reach the unit's identity. Measured on
	 * the reference application: 19 test files execute that mapper, and keying
	 * source files on their own bytes alone would have banked 87 stale proofs the
	 * moment it changed.
	 */
	public function testIncludesProductionCodeExecutedOnlyToBuildAFixture(): void {
		$ownership = new OwnershipMap(
			owns: [
				"tests/Unit/OrderStorerTest.php" => [
					"src/Domain/Order/OrderStorer.php",
					"src/Infrastructure/Order/NormalizedOrderMapper.php",
				],
			],
			owners: [
				"src/Domain/Order/OrderStorer.php" => ["tests/Unit/OrderStorerTest.php"],
				"src/Infrastructure/Order/NormalizedOrderMapper.php" => ["tests/Unit/OrderStorerTest.php"],
			],
		);

		self::assertSame(
			["src/Infrastructure/Order/NormalizedOrderMapper.php"],
			$this->resolver->resolve($ownership, "src/Domain/Order/OrderStorer.php"),
		);
	}

	/**
	 * A file two hops away is reached only by some other test that happens to
	 * share a file with this one. It never executes in this file's tests, so it
	 * cannot change this file's verdict. Measured on the reference application:
	 * one hop is a median of 11 files of 404, the transitive closure is 383.
	 */
	public function testStopsAtOneHopAndDoesNotCloseTransitively(): void {
		$ownership = new OwnershipMap(
			owns: [
				"tests/Unit/ATest.php" => ["src/A.php", "src/Shared.php"],
				"tests/Unit/BTest.php" => ["src/B.php", "src/Shared.php"],
			],
			owners: [
				"src/A.php" => ["tests/Unit/ATest.php"],
				"src/B.php" => ["tests/Unit/BTest.php"],
				"src/Shared.php" => ["tests/Unit/ATest.php", "tests/Unit/BTest.php"],
			],
		);

		// `src/B.php` is two hops from `src/A.php` through `src/Shared.php`, and is
		// executed by no test that executes `src/A.php`.
		self::assertSame(["src/Shared.php"], $this->resolver->resolve($ownership, "src/A.php"));
	}

	/**
	 * A file shared by two tests does see both of their footprints, because both
	 * tests genuinely execute it.
	 */
	public function testUnionsTheFootprintsOfEveryCoveringTest(): void {
		$ownership = new OwnershipMap(
			owns: [
				"tests/Unit/ATest.php" => ["src/A.php", "src/Shared.php"],
				"tests/Unit/BTest.php" => ["src/B.php", "src/Shared.php"],
			],
			owners: [
				"src/A.php" => ["tests/Unit/ATest.php"],
				"src/B.php" => ["tests/Unit/BTest.php"],
				"src/Shared.php" => ["tests/Unit/ATest.php", "tests/Unit/BTest.php"],
			],
		);

		self::assertSame(["src/A.php", "src/B.php"], $this->resolver->resolve($ownership, "src/Shared.php"));
	}

	/**
	 * The file's own digest is already a separate term of the identity. Carrying it
	 * here as well would make the hash depend on which of the two terms a reader
	 * believed held it.
	 */
	public function testNeverIncludesTheFileItself(): void {
		$ownership = new OwnershipMap(
			owns: ["tests/Unit/ATest.php" => ["src/A.php"]],
			owners: ["src/A.php" => ["tests/Unit/ATest.php"]],
		);

		self::assertSame([], $this->resolver->resolve($ownership, "src/A.php"));
	}

	public function testResolvesAFileNoTestExecutesToNothing(): void {
		$ownership = new OwnershipMap(
			owns: ["tests/Unit/ATest.php" => ["src/A.php"]],
			owners: ["src/A.php" => ["tests/Unit/ATest.php"]],
		);

		self::assertSame([], $this->resolver->resolve($ownership, "src/Unreached.php"));
	}

	/**
	 * A test file discovered but covering nothing contributes no co-execution, and
	 * must not make the lookup fail.
	 */
	public function testToleratesACoveringTestThatOwnsNothing(): void {
		$ownership = new OwnershipMap(
			owns: ["tests/Unit/SilentTest.php" => []],
			owners: ["src/A.php" => ["tests/Unit/SilentTest.php"]],
		);

		self::assertSame([], $this->resolver->resolve($ownership, "src/A.php"));
	}

	public function testReturnsASortedListSoTwoRunsHashIdentically(): void {
		$ownership = new OwnershipMap(
			owns: ["tests/Unit/ATest.php" => ["src/Zebra.php", "src/A.php", "src/Apple.php"]],
			owners: ["src/A.php" => ["tests/Unit/ATest.php"]],
		);

		self::assertSame(["src/Apple.php", "src/Zebra.php"], $this->resolver->resolve($ownership, "src/A.php"));
	}
}
