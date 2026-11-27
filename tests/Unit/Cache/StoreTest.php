<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Cache\Lapse;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Verdict;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase {

	private const string PATH = "/p/.mule/verified.json";

	private InMemoryFilesystem $filesystem;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem([], [], ["/p/.mule"]);
	}

	/**
	 * @return Store
	 */
	private function store(): Store {
		return new Store($this->filesystem, self::PATH);
	}

	/**
	 * A reason the store would carry for a file nothing proved. The tests that
	 * care about the reason build their own; these only care that it travels.
	 *
	 * @param list<string> $paths
	 * @return array<string, Lapse>
	 */
	private function lapses(array $paths): array {
		$lapses = [];

		foreach($paths as $path) {
			$lapses[$path] = new Lapse(4, 3, 1, 0, 10, 10);
		}

		return $lapses;
	}

	/**
	 * @param array<string, int<0, max>> $proven
	 * @param list<string> $unproven
	 * @param array<string, Footprint> $tests
	 * @return Snapshot
	 */
	private function snapshot(
		array $proven = [],
		array $unproven = [],
		string $toolchain = "chain",
		array $tests = [],
	): Snapshot {
		/** @var array<string, Footprint> $tests */
		/** @var non-empty-string $toolchain */
		$verdicts = [];

		foreach($proven as $path => $mutants) {
			$verdicts[$path] = new Verdict("key-of-{$path}", $mutants);
		}

		return new Snapshot($verdicts, $this->lapses($unproven), $tests, $toolchain);
	}

	/**
	 * @param Snapshot $snapshot
	 * @return void
	 */
	private function given(Snapshot $snapshot): void {
		$this->filesystem->write(self::PATH, StoreFormat::write($snapshot));
	}

	/**
	 * The first run has nothing to inherit, and an empty snapshot is exactly
	 * what it should merge into.
	 *
	 * @return void
	 */
	public function testAMissingStoreReadsAsEmptyRatherThanAsAFailure(): void {
		$read = $this->store()->read("chain");

		self::assertSame([], $read->verdicts);
		self::assertSame([], array_keys($read->unproven));
		self::assertSame("chain", $read->toolchain);
	}

	public function testReadsBackWhatWasBanked(): void {
		$this->given($this->snapshot(["src/A.php" => 4], ["src/B.php"]));

		$read = $this->store()->read("chain");

		self::assertTrue($read->vouchesFor("src/A.php", "key-of-src/A.php"));
		self::assertSame(["src/B.php"], array_keys($read->unproven));
	}

	/**
	 * Returned as it was found rather than quietly replaced: whether the stored
	 * environment still describes this one is a decision, and the run that
	 * notices should be able to say so.
	 *
	 * @return void
	 */
	public function testAStoreFromAnotherToolchainIsReturnedAsItWasFound(): void {
		$this->given($this->snapshot(["src/A.php" => 4], [], "an older gate"));

		$read = $this->store()->read("chain");

		self::assertFalse($read->describes("chain"));
		self::assertSame("an older gate", $read->toolchain);
	}

	public function testBankingWritesAStoreThatReadsBack(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertTrue($this->store()->read("chain")->vouchesFor("src/A.php", "key-of-src/A.php"));
	}

	public function testBankingMergesRatherThanReplaces(): void {
		$this->given($this->snapshot(["src/A.php" => 4]));

		$merged = $this->store()->bank($this->snapshot(["src/B.php" => 2]));

		self::assertSame(["src/A.php", "src/B.php"], array_keys($merged->verdicts));
		self::assertSame(["src/A.php", "src/B.php"], array_keys($this->store()->read("chain")->verdicts));
	}

	/**
	 * The lock is taken, and it is taken around the read as well as the write —
	 * which is the whole point. Two runs finishing together otherwise means the
	 * later one's file wins and the earlier one's entries are lost.
	 *
	 * @return void
	 */
	public function testBankingHappensUnderALock(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertSame(["/p/.mule/verified.json.lock"], $this->filesystem->locked);
	}

	/**
	 * Written beside the old one and moved over, so a reader sees one complete
	 * store or the other and a crash leaves a scratch file rather than half a
	 * store.
	 *
	 * @return void
	 */
	public function testTheStoreIsReplacedRatherThanEdited(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertSame(["/p/.mule/.gitignore", self::PATH], array_keys($this->filesystem->files));
	}

	/**
	 * The lock and the half-written scratch file are mule's, transient and not to
	 * be committed — and a consumer who finds them staged is a consumer who
	 * ignores `.mule` wholesale, after which the store stops travelling.
	 *
	 * @return void
	 */
	public function testTheStoreIgnoresItsOwnTransientNeighbours(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertSame("*.lock\n*.writing\n", $this->filesystem->read("/p/.mule/.gitignore"));
	}

	public function testBankingCreatesTheDirectoryWhenItIsNotThere(): void {
		$this->filesystem = new InMemoryFilesystem();

		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertSame(["/p/.mule"], $this->filesystem->directories);
	}

	public function testBankingLeavesADirectoryThatIsAlreadyThereAlone(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertSame(["/p/.mule"], $this->filesystem->directories);
	}

	/**
	 * A gate change leaves nothing standing. Measured on a real project, merging
	 * forward left 610 verdicts of which one run had earned 221.
	 *
	 * @return void
	 */
	public function testBankingUnderANewToolchainReplacesTheStore(): void {
		$this->given($this->snapshot(["src/A.php" => 4, "src/B.php" => 2], [], "an older gate"));

		$merged = $this->store()->bank($this->snapshot(["src/A.php" => 9]));

		self::assertSame(["src/A.php"], array_keys($merged->verdicts));
		self::assertSame(9, $merged->verdicts["src/A.php"]->mutants);
		self::assertSame("chain", $merged->toolchain);
	}

	/**
	 * What the second of two concurrent runs sees: the first one's entries are
	 * already there, because the re-read happens inside the lock rather than
	 * before it.
	 *
	 * @return void
	 */
	public function testARunBanksIntoWhateverAnotherRunLeftBehind(): void {
		$store = $this->store();
		$store->bank($this->snapshot(["src/A.php" => 4]));
		$store->bank($this->snapshot(["src/B.php" => 2]));

		self::assertSame(["src/A.php", "src/B.php"], array_keys($store->read("chain")->verdicts));
	}

	public function testBankingReturnsTheStoreAsItNowStands(): void {
		$this->given($this->snapshot(["src/A.php" => 4]));

		self::assertSame(6, $this->store()->bank($this->snapshot(["src/B.php" => 2]))->mutants());
	}
}
