<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Toolchain;
use PHPUnit\Framework\TestCase;

final class ToolchainTest extends TestCase {

	/**
	 * @param array<string, bool> $mutators
	 * @return non-empty-string
	 */
	private function chain(
		string $lock = "lock",
		string $phpunit = "pu",
		string $infection = "inf",
		array $mutators = ["@default" => TRUE, "@identical" => TRUE],
		string $support = "support",
		string $php = "8.4.28",
		string $driver = "Xdebug 3.4.0",
		string $mule = "5.0.0",
	): string {
		return Toolchain::of($lock, $phpunit, $infection, $mutators, $support, $php, $driver, $mule);
	}

	public function testTheSameToolchainGivesTheSameDigest(): void {
		self::assertSame($this->chain(), $this->chain());
	}

	public function testTheMutatorSetIsOrderIndependent(): void {
		self::assertSame(
			$this->chain(mutators: ["@default" => TRUE, "@identical" => TRUE]),
			$this->chain(mutators: ["@identical" => TRUE, "@default" => TRUE]),
		);
	}

	/**
	 * **A disabled mutator is not an absent one.** `{"@default": false}` is a
	 * different gate from `{"@default": true}`, so a set digested by its names
	 * alone would call the two the same arrangement — which the mutation gate
	 * found by unwrapping an `array_keys` and nothing noticing.
	 *
	 * @return void
	 */
	public function testTurningAMutatorOffIsADifferentGate(): void {
		self::assertNotSame(
			$this->chain(mutators: ["@default" => TRUE]),
			$this->chain(mutators: ["@default" => FALSE]),
		);
	}

	/**
	 * Each part on its own, because each one changes what a verdict means and a
	 * key that noticed only some of them would bank a claim nothing proved.
	 *
	 * @return void
	 */
	public function testEveryPartChangesTheDigest(): void {
		$base = $this->chain();

		self::assertNotSame($base, $this->chain(lock: "upgraded"));
		self::assertNotSame($base, $this->chain(phpunit: "excludes one more file"));
		self::assertNotSame($base, $this->chain(infection: "a different gate"));
		self::assertNotSame($base, $this->chain(mutators: ["@default" => TRUE]));
		self::assertNotSame($base, $this->chain(support: "a fake that behaves differently"));
		self::assertNotSame($base, $this->chain(php: "8.5.0"));
		self::assertNotSame($base, $this->chain(driver: "pcov 1.0.11"));
		self::assertNotSame($base, $this->chain(mule: "5.0.1"));
	}

	public function testTheDigestIsASha256(): void {
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->chain());
	}

	public function testDigestsAStructureRatherThanAJoinedString(): void {
		self::assertNotSame(
			Toolchain::digest(["a" => "b:c"]),
			Toolchain::digest(["a" => "b", "c" => ""]),
		);
	}
}
