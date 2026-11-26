<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Key;
use PHPUnit\Framework\TestCase;

final class KeyTest extends TestCase {

	/**
	 * @param array<string, non-empty-string> $deps
	 * @param array<string, non-empty-string> $tests
	 * @return non-empty-string
	 */
	private function key(
		string $path = "src/A.php",
		string $digest = "a",
		array $deps = ["src/A.php" => "a", "src/B.php" => "b"],
		array $tests = ["tests/ATest.php" => "t"],
		string $toolchain = "chain",
	): string {
		/**
		 * @var non-empty-string $digest
		 * @var non-empty-string $toolchain
		 */
		return Key::of($path, $digest, $deps, $tests, $toolchain);
	}

	public function testTheSameInputsGiveTheSameKey(): void {
		self::assertSame($this->key(), $this->key());
	}

	/**
	 * Both maps, separately. Coverage arrives in whatever order a report or a
	 * cache happened to list it, and the key must not.
	 *
	 * @return void
	 */
	public function testTheOrderOfTheDependenciesDoesNotMatter(): void {
		self::assertSame(
			$this->key(deps: ["src/A.php" => "a", "src/B.php" => "b"]),
			$this->key(deps: ["src/B.php" => "b", "src/A.php" => "a"]),
		);
	}

	public function testTheOrderOfTheCoveringTestsDoesNotMatter(): void {
		self::assertSame(
			$this->key(tests: ["tests/ATest.php" => "t", "tests/BTest.php" => "u"]),
			$this->key(tests: ["tests/BTest.php" => "u", "tests/ATest.php" => "t"]),
		);
	}

	public function testTheFilesOwnBytesChangeIt(): void {
		self::assertNotSame($this->key(), $this->key(digest: "moved"));
	}

	/**
	 * A rename is a new file: the mutants a cached verdict describes carry the
	 * old path, so keeping the verdict would report findings against a file that
	 * no longer exists.
	 *
	 * @return void
	 */
	public function testThePathChangesIt(): void {
		self::assertNotSame($this->key(), $this->key(path: "src/Renamed.php"));
	}

	/**
	 * The case the whole breadth of the key is for: a file this one never
	 * mentions, reached only because a shared test executes both.
	 *
	 * @return void
	 */
	public function testADependencyMovingChangesIt(): void {
		self::assertNotSame($this->key(), $this->key(deps: ["src/A.php" => "a", "src/B.php" => "moved"]));
	}

	public function testADependencyAppearingChangesIt(): void {
		self::assertNotSame(
			$this->key(),
			$this->key(deps: ["src/A.php" => "a", "src/B.php" => "b", "src/C.php" => "c"]),
		);
	}

	public function testACoveringTestMovingChangesIt(): void {
		self::assertNotSame($this->key(), $this->key(tests: ["tests/ATest.php" => "moved"]));
	}

	public function testACoveringTestAppearingChangesIt(): void {
		self::assertNotSame(
			$this->key(),
			$this->key(tests: ["tests/ATest.php" => "t", "tests/BTest.php" => "u"]),
		);
	}

	public function testTheToolchainChangesIt(): void {
		self::assertNotSame($this->key(), $this->key(toolchain: "upgraded"));
	}

	/**
	 * The sections are labelled, so the same path carrying the same digest as a
	 * dependency and as a covering test are different claims. An unlabelled
	 * union of the two maps could not tell them apart.
	 *
	 * @return void
	 */
	public function testADependencyAndACoveringTestAreNotInterchangeable(): void {
		self::assertNotSame(
			Key::of("src/A.php", "a", ["shared.php" => "s"], [], "chain"),
			Key::of("src/A.php", "a", [], ["shared.php" => "s"], "chain"),
		);
	}

	/**
	 * Hashing over a structure rather than a joined string. Any separator is a
	 * character a path can contain, and two different inputs joining to one
	 * string would share a key — the single failure a digest exists to prevent.
	 *
	 * @return void
	 */
	public function testPathsCarryingDelimitersDoNotCollide(): void {
		/** @var array<string, non-empty-string> $awkward */
		$awkward = ['x":"y' => "1"];
		/** @var array<string, non-empty-string> $plain */
		$plain = ["x" => "y", "1" => "1"];

		self::assertNotSame(
			Key::of("src/A.php", "a", $awkward, [], "chain"),
			Key::of("src/A.php", "a", $plain, [], "chain"),
		);
	}

	public function testTheKeyIsASha256(): void {
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key());
	}
}
