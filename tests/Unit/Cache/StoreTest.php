<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Cache\Verdict;
use Kristos80\Mule\Cache\Verdicts;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\FixedRandom;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Store::class)]
final class StoreTest extends TestCase {

	private const string FILE = "/project/.mule/verified.json";

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())->given("/project/composer.json", "{}");
	}

	public function testClaimsNothingBeforeItHasBeenWritten(): void {
		self::assertSame(0, $this->store()->read()->count());
	}

	public function testReadsBackWhatWasRecorded(): void {
		$this->filesystem->given(self::FILE, self::verdicts(["src/A.php"])->toJson());

		self::assertSame(["src/A.php"], $this->store()->read()->paths());
	}

	public function testRecordsTheNamedFilesWhereTheStoreBelongs(): void {
		$banked = $this->store()->bank(self::verdicts(["src/A.php", "src/B.php"]), ["src/A.php"]);

		self::assertSame(1, $banked);
		self::assertSame(self::verdicts(["src/A.php"])->toJson(), $this->filesystem->contentsOf(self::FILE));
	}

	public function testRecordsEveryFileOfAWholeProjectRun(): void {
		$current = self::verdicts(["src/A.php", "src/B.php"]);

		self::assertSame(2, $this->store()->bank($current, $current->paths()));
		self::assertSame($current->toJson(), $this->filesystem->contentsOf(self::FILE));
	}

	public function testKeepsWhatAnEarlierRunRecordedAboutOtherFiles(): void {
		$this->filesystem->given(self::FILE, self::verdicts(["src/Old.php"])->toJson());

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(self::verdicts(["src/A.php", "src/Old.php"])->toJson(), $this->filesystem->contentsOf(self::FILE));
	}

	public function testWritesOverWhatAnEarlierRunRecordedAboutTheSameFile(): void {
		$this->filesystem->given(self::FILE, Verdicts::of(["src/A.php" => new Verdict("old", "old")])->toJson());

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(self::verdicts(["src/A.php"])->toJson(), $this->filesystem->contentsOf(self::FILE));
	}

	public function testLeavesNoScratchFileBehind(): void {
		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(
			["/", "/project", "/project/.mule", "/project/composer.json", self::FILE],
			$this->filesystem->paths(),
		);
	}

	public function testWritesIntoADirectoryThatIsAlreadyThere(): void {
		$this->filesystem->given(self::FILE, "{}");

		$this->store()->bank(self::verdicts(["src/A.php"]), ["src/A.php"]);

		self::assertSame(self::verdicts(["src/A.php"])->toJson(), $this->filesystem->contentsOf(self::FILE));
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
	 * @return Verdicts
	 */
	private static function verdicts(array $paths): Verdicts {
		$verdicts = [];

		foreach($paths as $path) {
			$verdicts[$path] = new Verdict("source-of-{$path}", "tests-of-{$path}");
		}

		return Verdicts::of($verdicts);
	}
}
