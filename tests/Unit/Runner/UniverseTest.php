<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Universe;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;

final class UniverseTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem(
			files: [
				"/p/src/A.php" => "<?php // a",
				"/p/src/Deep/B.php" => "<?php // b",
				"/p/src/README.md" => "not php",
				"/p/tests/Unit/AaaTest.php" => "<?php // test",
				"/p/tests/Support/Fake.php" => "<?php // fake",
			],
			directories: ["/p/src", "/p/src/Deep", "/p/tests/Unit", "/p/tests/Support"],
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return Universe
	 */
	private function universe(array $settings = []): Universe {
		return new Universe(
			$this->filesystem,
			new Project($this->filesystem, "/p"),
			Configuration::of(["tests" => "tests/Unit", "support" => ["tests/Support"], ...$settings]),
		);
	}

	/**
	 * **G9.** The tree is enumerated, never the store: a loop driven by what the
	 * store already knows can only rediscover what it already knows, which is how
	 * a file silently stops being gated.
	 *
	 * @return void
	 */
	public function testEveryFileWhoseBytesCanChangeAVerdict(): void {
		self::assertSame(
			["src/A.php", "src/Deep/B.php", "tests/Unit/AaaTest.php"],
			array_keys($this->universe()->digests()),
		);
	}

	/**
	 * **Sorted across the roots, because the first name in it reaches a message.**
	 * A file the store has never heard of makes the run whole and names one of
	 * them, and which one is otherwise whichever root happened to be walked first.
	 *
	 * @return void
	 */
	public function testTheDigestMapIsSortedWhicheverRootCameFirst(): void {
		$this->filesystem->files["/p/spec/AaaTest.php"] = "<?php // test";
		$this->filesystem->directories[] = "/p/spec";

		self::assertSame(
			["spec/AaaTest.php", "src/A.php", "src/Deep/B.php"],
			array_keys($this->universe(["tests" => "spec"])->digests()),
		);
	}

	public function testNothingButPhp(): void {
		self::assertArrayNotHasKey("src/README.md", $this->universe()->digests());
	}

	public function testTheDigestIsTheFilesOwn(): void {
		self::assertSame(
			hash("sha256", "<?php // a"),
			$this->universe()->digests()["src/A.php"],
		);
	}

	/**
	 * **Support files are deliberately absent from the digest map.** A path in it
	 * that no part of the store mentions reads as "the store describes a different
	 * tree" and would make every run a cold one — and the store cannot mention a
	 * support file, because nothing instrumented ever names one.
	 *
	 * @return void
	 */
	public function testSupportFilesAreNotInTheDigestMap(): void {
		self::assertArrayNotHasKey("tests/Support/Fake.php", $this->universe()->digests());
	}

	/**
	 * They go into one digest instead, where invalidating everything at once is
	 * what the toolchain section already means.
	 *
	 * @return void
	 */
	public function testSupportGoesIntoOneDigestInstead(): void {
		$before = $this->universe()->support();
		$this->filesystem->files["/p/tests/Support/Fake.php"] = "<?php // behaves differently now";

		self::assertNotSame($before, $this->universe()->support());
	}

	public function testRenamingASupportFileMovesTheDigest(): void {
		$before = $this->universe()->support();
		unset($this->filesystem->files["/p/tests/Support/Fake.php"]);
		$this->filesystem->files["/p/tests/Support/Double.php"] = "<?php // fake";

		self::assertNotSame($before, $this->universe()->support());
	}

	public function testNoSupportDirectoriesIsStillADigest(): void {
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{64}$/',
			$this->universe(["support" => []])->support(),
		);
	}

	/**
	 * **The list a cold run keys**, which is how a source file no test ever
	 * reaches gets a verdict of "unproven" rather than no verdict at all.
	 *
	 * @return void
	 */
	public function testTheFilesMuleIsAnswerableFor(): void {
		self::assertSame(["src/A.php", "src/Deep/B.php"], $this->universe()->sources());
	}

	public function testAnExcludedFileIsNotGated(): void {
		self::assertSame(["src/A.php"], $this->universe(["exclude" => ["src/Deep"]])->sources());
	}

	/**
	 * An excluded file does not end the walk either, which is the difference
	 * between "the rest of the tree is not gated" and "this one is not".
	 *
	 * @return void
	 */
	public function testWhatComesAfterAnExcludedFileIsStillGated(): void {
		self::assertSame(["src/Deep/B.php"], $this->universe(["exclude" => ["src/A.php"]])->sources());
	}

	/**
	 * Counted and reported rather than merely skipped: the difference between a
	 * project with two ungated files and one with two hundred is not visible in a
	 * passing run otherwise.
	 *
	 * @return void
	 */
	public function testWhatIsNotGatedIsStillCounted(): void {
		self::assertSame(["src/Deep/B.php"], $this->universe(["exclude" => ["src/Deep"]])->excluded());
	}

	public function testNothingIsExcludedWhenNobodySaidSo(): void {
		self::assertSame([], $this->universe()->excluded());
	}

	public function testAnExcludedFileIsNotDigestedEither(): void {
		self::assertArrayNotHasKey(
			"src/Deep/B.php",
			$this->universe(["exclude" => ["src/Deep/B.php"]])->digests(),
		);
	}

	/**
	 * **A missing root is not an error.** `support` is a list a consumer writes,
	 * and a directory that is not there yet contributes no bytes — the same answer
	 * as an empty one, and a less confusing one than a crash inside a digest loop.
	 *
	 * @return void
	 */
	public function testADirectoryThatIsNotThereYet(): void {
		self::assertSame([], $this->universe(["source" => "app"])->sources());
	}
}
