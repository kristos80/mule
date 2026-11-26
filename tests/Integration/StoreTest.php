<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Cache\StoreFormat;
use Kristos80\Mule\Cache\Verdict;
use Kristos80\Mule\External\PhpFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * The store against a real filesystem.
 *
 * The unit suite proves the decisions; these are the states only a disk can be
 * in — a scratch file left by a crash, two writers, and a store that has to be
 * byte-identical after a round trip through `rename`. They are §7's scenario
 * table, as far as it can be reached without the engine.
 */
final class StoreTest extends TestCase {

	use TemporaryTree;

	/**
	 * @param array<string, int<0, max>> $proven
	 * @param list<string> $unproven
	 * @param array<string, list<string>> $tests
	 * @return Snapshot
	 */
	private function snapshot(
		array $proven = [],
		array $unproven = [],
		string $toolchain = "chain",
		array $tests = [],
	): Snapshot {
		/** @var non-empty-string $toolchain */
		$verdicts = [];

		foreach($proven as $path => $mutants) {
			$verdicts[$path] = new Verdict("key-of-{$path}", $mutants);
		}

		return new Snapshot($verdicts, $unproven, $tests, $toolchain);
	}

	/**
	 * @return Store
	 */
	private function store(): Store {
		return new Store(new PhpFilesystem(), $this->at(".mule/verified.json"));
	}

	public function testBanksIntoADirectoryThatDoesNotExistYet(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertFileExists($this->at(".mule/verified.json"));
	}

	public function testTheBankedStoreIsValidJsonAPersonCanRead(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4], ["src/B.php"]));

		$contents = $this->contents($this->at(".mule/verified.json"));

		self::assertJson($contents);
		self::assertStringContainsString("\n    \"verdicts\"", $contents);
		self::assertStringEndsWith("\n", $contents);
	}

	/**
	 * The bytes have to survive a real write, a real rename and a real read, or
	 * a committed store is a diff nobody can review.
	 *
	 * @return void
	 */
	public function testTheBytesSurviveTheRoundTripThroughTheDisk(): void {
		$snapshot = $this->snapshot(["src/A.php" => 4, "src/B.php" => 0], ["src/C.php"]);

		$this->store()->bank($snapshot);

		self::assertSame(
			StoreFormat::write($snapshot),
			$this->contents($this->at(".mule/verified.json")),
		);
	}

	/**
	 * Banking twice has to be idempotent at the byte level, or every run would
	 * show a diff and the store would stop being reviewable.
	 *
	 * @return void
	 */
	public function testBankingTheSameFindingsTwiceChangesNothing(): void {
		$store = $this->store();
		$store->bank($this->snapshot(["src/A.php" => 4]));
		$first = $this->contents($this->at(".mule/verified.json"));

		$store->bank($this->snapshot(["src/A.php" => 4]));

		self::assertSame($first, $this->contents($this->at(".mule/verified.json")));
	}

	/**
	 * **The replace, seen from the outside.** A crash between writing and moving
	 * leaves a scratch file; the next run must overwrite it rather than trip on
	 * it, and the store itself must be untouched in the meantime.
	 *
	 * @return void
	 */
	public function testAScratchFileLeftByACrashDoesNotStopTheNextRun(): void {
		$store = $this->store();
		$store->bank($this->snapshot(["src/A.php" => 4]));
		$good = $this->contents($this->at(".mule/verified.json"));

		file_put_contents($this->at(".mule/verified.json.writing"), "half a store");

		// the store is still the complete one, not the scratch
		self::assertSame($good, $this->contents($this->at(".mule/verified.json")));

		$store->bank($this->snapshot(["src/B.php" => 2]));

		self::assertSame(
			["src/A.php", "src/B.php"],
			array_keys($store->read("chain")->verdicts),
		);
		self::assertFileDoesNotExist($this->at(".mule/verified.json.writing"));
	}

	/**
	 * The lock file is left behind on purpose: it has no contents, and creating
	 * it is cheaper than reasoning about who may delete it while another process
	 * holds it.
	 *
	 * @return void
	 */
	public function testTheLockFileIsHarmlessAndStays(): void {
		$this->store()->bank($this->snapshot(["src/A.php" => 4]));

		self::assertFileExists($this->at(".mule/verified.json.lock"));
		self::assertSame("", $this->contents($this->at(".mule/verified.json.lock")));
	}

	/**
	 * Two writers, one after the other through the same lock, which is what two
	 * concurrent runs reduce to. Neither loses the other's entries.
	 *
	 * @return void
	 */
	public function testTwoRunsBankingInTurnKeepBothSetsOfEntries(): void {
		new Store(new PhpFilesystem(), $this->at(".mule/verified.json"))
			->bank($this->snapshot(["src/A.php" => 4]));
		new Store(new PhpFilesystem(), $this->at(".mule/verified.json"))
			->bank($this->snapshot(["src/B.php" => 2]));

		$read = $this->store()->read("chain");

		self::assertSame(["src/A.php", "src/B.php"], array_keys($read->verdicts));
		self::assertSame(6, $read->mutants());
	}

	/**
	 * The revert case, which v4's log calls the hardest to recover from: the
	 * bytes come back, so the file keys the same again, and the store still
	 * vouches for it. That is correct — the verdict was proven against exactly
	 * these bytes — and it is only safe because banking re-digests rather than
	 * trusting the snapshot it started from.
	 *
	 * @return void
	 */
	public function testAFileEditedAndRevertedIsVouchedForAgain(): void {
		$store = $this->store();
		$store->bank($this->snapshot(["src/A.php" => 4]));

		// edited: the key moves, so the store no longer vouches
		self::assertSame(["src/A.php"], $store->read("chain")->outstanding(["src/A.php" => "edited"]));

		// reverted: the same bytes key the same way, and the verdict holds
		self::assertSame([], $store->read("chain")->outstanding(["src/A.php" => "key-of-src/A.php"]));
	}
}
