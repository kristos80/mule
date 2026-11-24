<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Freshness;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Touched;
use Kristos80\Mule\Cache\Universe;
use Kristos80\Mule\Cache\Unproven;
use Kristos80\Mule\Coverage\Unreached;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\MappedClasses;
use PHPUnit\Framework\TestCase;

/**
 * The half of the cache that answers without a coverage report.
 *
 * Every one of these is the question "can the store still speak for this
 * tree", asked of a tree that exists only as an array.
 */
final class FreshnessTest extends TestCase {

	private const string SOURCE = "<?php class A {}";

	private const string TEST = "<?php class ATest {}";

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", "{}")
			->given("/project/src/A.php", self::SOURCE)
			->given("/project/tests/ATest.php", self::TEST);
	}

	public function testCannotSpeakForATreeItHasRecordedNothingAbout(): void {
		$standing = $this->freshness()->of(Snapshot::of([], []));

		self::assertFalse($standing->usable);
		self::assertFalse($standing->isCurrent());
	}

	public function testSpeaksForATreeNothingHasMovedIn(): void {
		$standing = $this->freshness()->of($this->stored());

		self::assertTrue($standing->isSettled());
		self::assertSame([], $standing->stale);
		self::assertSame(1, $standing->total);
	}

	public function testFindsTheFileWhoseOwnBytesMoved(): void {
		$this->filesystem->given("/project/src/A.php", "<?php class A { public function one() {} }");

		$standing = $this->freshness()->of($this->stored());

		self::assertTrue($standing->usable);
		self::assertSame(["src/A.php"], $standing->stale);
		self::assertFalse($standing->isSettled());
	}

	public function testFindsTheFileWhoseTestMoved(): void {
		$this->filesystem->given("/project/tests/ATest.php", "<?php class ATest { /* weaker */ }");

		self::assertSame(["src/A.php"], $this->freshness()->of($this->stored())->stale);
	}

	public function testRefusesWhenAFileItWatchesMoved(): void {
		$this->filesystem->given("/project/composer.json", '{"name": "acme/thing"}');

		self::assertFalse($this->freshness()->of($this->stored())->usable);
	}

	public function testRefusesWhenAFileItCouldNotProveMoved(): void {
		$stored = $this->storedWithAGap();

		$this->filesystem->given("/project/src/B.php", "<?php class B { public function two() {} }");

		self::assertFalse($this->freshness()->of($stored)->usable);
	}

	public function testRefusesWhenSomethingItRecordedIsGone(): void {
		$this->filesystem->delete("/project/tests/ATest.php");

		self::assertFalse($this->freshness()->of($this->stored())->usable);
	}

	public function testRefusesWhenAPhpFileItHasNeverHeardOfAppears(): void {
		$this->filesystem->given("/project/src/New.php", "<?php class New_ {}");

		self::assertFalse($this->freshness()->of($this->stored())->usable);
	}

	public function testIgnoresSomethingThatAppearsThatIsNotPhp(): void {
		$this->filesystem->given("/project/src/notes.md", "# notes");

		self::assertTrue($this->freshness()->of($this->stored())->usable);
	}

	public function testIgnoresADeclaredDirectoryThatIsNotThere(): void {
		$freshness = new Freshness(
			$this->project(),
			$this->filesystem,
			$this->universe(["/project/src", "/project/tests", "/project/gone"]),
		);

		self::assertTrue($freshness->of($this->stored())->usable);
	}

	public function testIgnoresAnAutoloadedFileOutsideTheProject(): void {
		$this->filesystem->given("/elsewhere/Vendored.php", "<?php class Vendored {}");

		$freshness = new Freshness(
			$this->project(),
			$this->filesystem,
			$this->universe(["/project/src", "/project/tests", "/elsewhere"]),
		);

		self::assertTrue($freshness->of($this->stored())->usable);
	}

	public function testAsksForNoTestsWhenNothingHasMoved(): void {
		self::assertSame([], $this->freshness()->of($this->stored())->tests);
	}

	public function testAsksForTheTestThatChanged(): void {
		$this->filesystem->given("/project/tests/ATest.php", "<?php class ATest { /* weaker */ }");

		self::assertSame(["tests/ATest.php"], $this->freshness()->of($this->stored())->tests);
	}

	public function testAsksForTheTestThatRunsAFileThatChanged(): void {
		$this->filesystem->given("/project/src/A.php", "<?php class A { public function one() {} }");

		self::assertSame(["tests/ATest.php"], $this->freshness()->of($this->stored())->tests);
	}

	public function testAsksForTheTestThatRunsAFileNothingCouldProve(): void {
		$stored = Snapshot::of(
			["src/A.php" => hash("sha256", self::SOURCE)],
			["tests/ATest.php" => new Touched(hash("sha256", self::TEST), ["src/A.php", "src/B.php"])],
			["src/B.php" => new Unproven(hash("sha256", "<?php class B {}"), [])],
			["composer.json" => hash("sha256", "{}")],
		);

		$this->filesystem->given("/project/src/B.php", "<?php class B {}");

		self::assertSame(["tests/ATest.php"], $this->freshness()->of($stored)->tests);
	}

	public function testAsksForEveryTestThatMattersRatherThanTheFirst(): void {
		$stored = Snapshot::of(
			["src/A.php" => hash("sha256", self::SOURCE)],
			[
				"tests/ATest.php" => new Touched(hash("sha256", self::TEST), ["src/A.php"]),
				"tests/BTest.php" => new Touched(hash("sha256", "<?php class BTest {}"), ["src/A.php"]),
			],
			[],
			["composer.json" => hash("sha256", "{}")],
		);

		$this->filesystem->given("/project/tests/BTest.php", "<?php class BTest {}");
		$this->filesystem->given("/project/src/A.php", "<?php class A { public function one() {} }");

		self::assertSame(["tests/ATest.php", "tests/BTest.php"], $this->freshness()->of($stored)->tests);
	}

	public function testDoesNotAskForATestThatRunsNothingOfInterest(): void {
		$stored = Snapshot::of(
			["src/A.php" => hash("sha256", self::SOURCE)],
			[
				"tests/ATest.php" => new Touched(hash("sha256", self::TEST), ["src/A.php"]),
				"tests/Other.php" => new Touched(hash("sha256", "<?php class Other {}"), []),
			],
			[],
			["composer.json" => hash("sha256", "{}")],
		);

		$this->filesystem->given("/project/tests/Other.php", "<?php class Other {}");
		$this->filesystem->given("/project/src/A.php", "<?php class A { public function one() {} }");

		self::assertSame(["tests/ATest.php"], $this->freshness()->of($stored)->tests);
	}

	public function testCarriesTheRecordedGapsAndCountsThemInTheTotal(): void {
		$standing = $this->freshness()->of($this->storedWithAGap());

		self::assertTrue($standing->isCurrent());
		self::assertFalse($standing->isSettled());
		self::assertSame(2, $standing->total);
		self::assertSame("two() lines 3-8, 2 unreached", $standing->gaps["src/B.php"][0]->described());
	}

	/**
	 * @return Freshness
	 */
	private function freshness(): Freshness {
		return new Freshness($this->project(), $this->filesystem, $this->universe(["/project/src", "/project/tests"]));
	}

	/**
	 * @param list<string> $directories
	 * @return Universe
	 */
	private function universe(array $directories): Universe {
		return new Universe($this->project(), $this->filesystem, new MappedClasses([], $directories));
	}

	/**
	 * @return Project
	 */
	private function project(): Project {
		return Project::containing("/project", $this->filesystem);
	}

	/**
	 * A store that describes the tree set up above.
	 *
	 * @return Snapshot
	 */
	private function stored(): Snapshot {
		return Snapshot::of(
			["src/A.php" => hash("sha256", self::SOURCE)],
			["tests/ATest.php" => new Touched(hash("sha256", self::TEST), ["src/A.php"])],
			[],
			["composer.json" => hash("sha256", "{}")],
		);
	}

	/**
	 * The same, plus a file the tests do not reach all of.
	 *
	 * @return Snapshot
	 */
	private function storedWithAGap(): Snapshot {
		$source = "<?php class B {}";

		$this->filesystem->given("/project/src/B.php", $source);

		return Snapshot::of(
			["src/A.php" => hash("sha256", self::SOURCE)],
			["tests/ATest.php" => new Touched(hash("sha256", self::TEST), ["src/A.php"])],
			["src/B.php" => new Unproven(hash("sha256", $source), [new Unreached("two", 3, 8, 2)])],
			["composer.json" => hash("sha256", "{}")],
		);
	}
}
