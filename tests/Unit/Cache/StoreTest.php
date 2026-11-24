<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Touched;
use Kristos80\Mule\Cache\Unproven;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\FixedRandom;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase {

	private const string FILE = "/project/.mule/verified.json";

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", "{}")
			->given("/project/src/A.php", "<?php class A {}")
			->given("/project/src/B.php", "<?php class B {}")
			->given("/project/src/Old.php", "<?php class Old {}");
	}

	public function testClaimsNothingBeforeItHasBeenWritten(): void {
		self::assertSame(0, $this->store()->read()->count());
	}

	public function testReadsBackWhatWasRecorded(): void {
		$this->filesystem->given(self::FILE, StoreFormat::write(self::verdicts(["src/A.php"])));

		self::assertSame(["src/A.php"], $this->store()->read()->paths());
	}

	public function testRecordsTheNamedFilesWhereTheStoreBelongs(): void {
		$banked = $this->store()->bank(self::verdicts(["src/A.php", "src/B.php"]), ["src/A.php"]);

		self::assertSame(1, $banked);
		self::assertSame(StoreFormat::write(self::verdicts(["src/A.php"])), $this->filesystem->contentsOf(self::FILE));
	}

	public function testRecordsEveryFileOfAWholeProjectRun(): void {
		$current = self::verdicts(["src/A.php", "src/B.php"]);

		self::assertSame(2, $this->store()->bank($current, $current->paths()));
		self::assertSame(StoreFormat::write($current), $this->filesystem->contentsOf(self::FILE));
	}

	public function testKeepsWhatAnEarlierRunRecordedAboutOtherFiles(): void {
		$this->filesystem->given(self::FILE, StoreFormat::write(self::verdicts(["src/Old.php"])));

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(StoreFormat::write(self::verdicts(["src/A.php", "src/Old.php"])), $this->filesystem->contentsOf(self::FILE));
	}

	public function testWritesOverWhatAnEarlierRunRecordedAboutTheSameFile(): void {
		$this->filesystem->given(self::FILE, StoreFormat::write(Snapshot::of(["src/A.php" => "old"], [])));

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(StoreFormat::write(self::verdicts(["src/A.php"])), $this->filesystem->contentsOf(self::FILE));
	}

	/**
	 * The merge keeps what nobody mentioned, which is right for a partial run
	 * and wrong for a deleted file — and a store holding a file the tree does
	 * not have is one no later run will answer from, with nothing able to
	 * repair it. So the write drops it.
	 *
	 * @return void
	 */
	public function testForgetsAFileThatIsNoLongerThere(): void {
		$this->filesystem->given(self::FILE, StoreFormat::write(self::verdicts(["src/A.php", "src/Old.php"])));
		$this->filesystem->delete("/project/src/Old.php");

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(["src/A.php"], $this->store()->read()->paths());
	}

	/**
	 * And from every section, not only the proven one: a deleted test, a
	 * deleted file nothing could prove and a deleted interface each stop the
	 * store describing the tree just as surely.
	 *
	 * What is still there stays, which is the other half of the assertion —
	 * pruning is for files that are gone, not a wipe.
	 *
	 * @return void
	 */
	public function testForgetsADeletedFileWhateverSectionItWasIn(): void {
		$this->filesystem->given("/project/src/Excluded.php", "<?php interface Excluded {}");
		$this->filesystem->given("/project/tests/ATest.php", "<?php class ATest {}");
		$this->filesystem->given(self::FILE, StoreFormat::write(Snapshot::of(
			["src/A.php" => "source-of-src/A.php"],
			["tests/ATest.php" => new Touched("tt", ["src/A.php"])],
			["src/B.php" => new Unproven("bb", [])],
			["src/Excluded.php" => "ee"],
			["composer.json" => "cc"],
		)));
		$this->filesystem->delete("/project/src/Excluded.php");
		$this->filesystem->delete("/project/src/B.php");
		$this->filesystem->delete("/project/tests/ATest.php");

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		$stored = $this->store()->read();

		self::assertSame([], $stored->watched(), "the deleted interface is gone.");
		self::assertSame([], $stored->tests(), "and the deleted test.");
		self::assertSame([], $stored->unproven(), "and the gap for a file that is not there.");
		self::assertSame(["src/A.php"], $stored->paths(), "what is still there stays.");
		self::assertSame(["composer.json" => "cc"], $stored->configuration());
	}

	public function testLeavesNoScratchFileBehind(): void {
		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(
			[
				"/",
				"/project",
				"/project/src",
				"/project/.mule",
				"/project/composer.json",
				"/project/src/A.php",
				"/project/src/B.php",
				"/project/src/Old.php",
				self::FILE,
			],
			$this->filesystem->paths(),
		);
	}

	public function testWritesIntoADirectoryThatIsAlreadyThere(): void {
		$this->filesystem->given(self::FILE, "{}");

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(StoreFormat::write(self::verdicts(["src/A.php"])), $this->filesystem->contentsOf(self::FILE));
	}

	/**
	 * The lock is outside the repository, named for the store it protects.
	 * Locking the store itself would hold an inode the very next statement
	 * replaces by rename — a lock the next process does not see — and locking
	 * anything in `.mule` would need a directory this run may have to create.
	 *
	 * @return void
	 */
	public function testTakesItsLockOutsideTheProjectNamedForTheStore(): void {
		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(["/tmp/mule-" . hash("sha256", self::FILE) . ".lock"], $this->filesystem->locks());
	}

	/**
	 * The point of the lock, and the half a rename cannot give: the store is
	 * *read* inside the critical section, so two runs cannot both merge onto the
	 * bytes they read before either wrote.
	 *
	 * @return void
	 */
	public function testMergesAndReplacesWhileHoldingIt(): void {
		$this->filesystem->given(self::FILE, StoreFormat::write(self::verdicts(["src/Old.php"])));

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame([
			"read /project/.mule/verified.json",
			"write /project/.mule/verified.json.cafe1234",
			"rename /project/.mule/verified.json.cafe1234 to /project/.mule/verified.json",
		], $this->filesystem->callsUnderLock());
	}

	/**
	 * @return Store
	 */
	private function store(): Store {
		return new Store(
			Project::containing("/project", $this->filesystem),
			$this->filesystem,
			new FixedRandom("cafe1234"),
		);
	}

	/**
	 * @param list<string> $paths
	 * @return Snapshot
	 */
	private static function verdicts(array $paths): Snapshot {
		$verified = [];

		foreach($paths as $path) {
			$verified[$path] = "source-of-{$path}";
		}

		return Snapshot::of($verified, []);
	}
}
