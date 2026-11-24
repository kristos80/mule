<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The bank, under the one condition the unit suite cannot arrange: several
 * processes finishing at the same time.
 *
 * **Replacing a file atomically is not the same as merging into it safely.** A
 * rename gives a reader the old store or the new one and never half of one,
 * which is a different property from two runs not overwriting each other's
 * work: both would read the store before either wrote, each would merge onto
 * the bytes it read, and the second rename would drop the first one's entries
 * silently. Nothing about that is visible in one process, so it is proved here.
 *
 * **Eight workers, twenty-five banks each**, because a single bank apiece would
 * pass by luck as often as not: two hundred read-modify-write cycles across
 * eight processes collide reliably, and every one of the two hundred entries has
 * to survive.
 */
final class BankTest extends TestCase {

	use TemporaryTree;

	private const int WORKERS = 8;

	private const int BANKS_EACH = 25;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->createTree();
		$this->write("composer.json", "{}");
		$this->write("bank.php", $this->worker());
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->removeTree();
	}

	public function testKeepsEveryEntryWhenEightProcessesBankAtOnce(): void {
		$this->runWorkersAtOnce();

		$store = json_decode((string) file_get_contents($this->path(".mule/verified.json")), TRUE);

		self::assertIsArray($store);
		self::assertIsArray($store["verified"]);
		self::assertCount(
			self::WORKERS * self::BANKS_EACH,
			$store["verified"],
			"An entry was lost: a merge overwrote another process's.",
		);

		foreach($this->expectedPaths() as $path) {
			self::assertArrayHasKey($path, $store["verified"]);
			self::assertSame(["digest" => "source-{$path}"], $store["verified"][$path]);
		}
	}

	public function testLeavesTheStoreReadableAndNoScratchFileBehind(): void {
		$this->runWorkersAtOnce();

		$entries = array_values(array_diff((array) scandir($this->path(".mule")), [".", ".."]));

		self::assertSame(["verified.json"], $entries);
	}

	/**
	 * Every path the workers between them record.
	 *
	 * @return list<string>
	 */
	private function expectedPaths(): array {
		$paths = [];

		for($worker = 0; $worker < self::WORKERS; $worker++) {
			for($bank = 0; $bank < self::BANKS_EACH; $bank++) {
				$paths[] = "src/W{$worker}/F{$bank}.php";
			}
		}

		return $paths;
	}

	/**
	 * Starts every worker in the background and waits for all of them, which is
	 * the whole point: they have to be inside the bank together.
	 *
	 * @return void
	 */
	private function runWorkersAtOnce(): void {
		$command = sprintf(
			"cd %s && for worker in $(seq 0 %d); do %s bank.php \$worker >>worker.log 2>&1 & done; wait",
			escapeshellarg($this->path()),
			self::WORKERS - 1,
			escapeshellarg(PHP_BINARY),
		);
		$status = 1;

		exec("sh -c " . escapeshellarg($command), $lines, $status);

		self::assertSame(0, $status, implode("\n", $lines));
		self::assertSame("", (string) @file_get_contents($this->path("worker.log")), "A worker failed.");
	}

	/**
	 * One worker: mule's own classes against the real filesystem, banking a
	 * verdict of its own twenty-five times over.
	 *
	 * @return string
	 */
	private function worker(): string {
		$autoload = realpath(__DIR__ . "/../../vendor/autoload.php");
		$banks = self::BANKS_EACH;

		return <<<PHP
			<?php
			declare(strict_types=1);

			require "{$autoload}";

			\$filesystem = new Kristos80\\Mule\\External\\PhpFilesystem();
			\$project = Kristos80\\Mule\\Project::containing(__DIR__, \$filesystem);
			\$store = new Kristos80\\Mule\\Cache\\Store(\$project, \$filesystem, new Kristos80\\Mule\\External\\PhpRandom());
			\$worker = \$argv[1];

			for(\$bank = 0; \$bank < {$banks}; \$bank++) {
				\$path = "src/W{\$worker}/F{\$bank}.php";
				\$snapshot = Kristos80\\Mule\\Cache\\Snapshot::of([\$path => "source-{\$path}"], []);

				\$store->bank(\$snapshot, [\$path]);
			}
			PHP;
	}
}
