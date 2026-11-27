<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Ownership;

use JsonException;
use Kristos80\Mule\Domain\Exception\DiscoveryException;
use Kristos80\Mule\Domain\Inventory\TestInventory;
use function array_fill_keys;
use function array_keys;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function ksort;
use function sort;
use function str_contains;
use function trim;
use const JSON_THROW_ON_ERROR;

/**
 * Builds the ownership relation from PHPUnit's per-test coverage records.
 *
 * **Every discovered test file starts out owning nothing.** A test that executes
 * no instrumented source is absent from the coverage report entirely, so seeding
 * from the inventory is what keeps such a file distinguishable from one mule has
 * never discovered. The first is a test with nothing to prove; the second means
 * discovery did not run, and the two call for opposite responses. Measured on
 * the reference application: seven of 334 test files are in that state today.
 */
final readonly class OwnershipReader implements OwnershipReaderInterface {

	/**
	 * @param string $jsonl The contents of the report's `tests.jsonl`.
	 * @param TestInventory $inventory
	 * @param string $sourceRoot Project-relative, as the report's own metadata gives it.
	 * @return OwnershipMap
	 * @throws DiscoveryException when a record is malformed, or credits a class the inventory does not list
	 */
	public function read(string $jsonl, TestInventory $inventory, string $sourceRoot): OwnershipMap {
		$owns = array_fill_keys($inventory->files, []);
		$owners = [];

		// **Each side is keyed by the path and holds the path.** Writing a `TRUE`
		// nobody reads is a value with no result behind it, and the mutation gate
		// says so: flipping it changes nothing any test can see.
		foreach($this->attributions($jsonl, $inventory, $sourceRoot) as [$test, $source]) {
			$owns[$test][$source] = $source;
			$owners[$source][$test] = $test;
		}

		return new OwnershipMap($this->sorted($owns), $this->sorted($owners));
	}

	/**
	 * Every (test file, source file) pair the report records, one at a time.
	 *
	 * @param string $jsonl
	 * @param TestInventory $inventory
	 * @param string $sourceRoot
	 * @return iterable<array{0: string, 1: string}>
	 * @throws DiscoveryException when a record is malformed, or credits a class the inventory does not list
	 */
	private function attributions(string $jsonl, TestInventory $inventory, string $sourceRoot): iterable {
		foreach(explode("\n", $jsonl) as $number => $line) {
			if(trim($line) === "") {
				continue;
			}

			[$test, $covers] = $this->parsed($line, $number + 1);
			$file = $this->fileOf($test, $inventory);

			foreach(array_keys($covers) as $path) {
				yield [$file, $sourceRoot === "" ? (string) $path : "{$sourceRoot}/{$path}"];
			}
		}
	}

	/**
	 * @param string $line
	 * @param int $number
	 * @return array{0: string, 1: array<array-key, mixed>}
	 * @throws DiscoveryException when the line is not a record naming a test and what it covers
	 */
	private function parsed(string $line, int $number): array {
		try {
			$record = json_decode($line, associative: TRUE, flags: JSON_THROW_ON_ERROR);
		} catch(JsonException $exception) {
			throw new DiscoveryException(
				"Line {$number} of the coverage report's per-test records is not valid JSON:"
				. " {$exception->getMessage()}",
				previous: $exception,
			);
		}

		$test = is_array($record) ? ($record["test"] ?? NULL) : NULL;
		$covers = is_array($record) ? ($record["covers"] ?? NULL) : NULL;

		if(!is_string($test) || !is_array($covers)) {
			throw new DiscoveryException(
				"Line {$number} of the coverage report's per-test records does not name a \"test\""
				. " and the files it \"covers\".",
			);
		}

		return [$test, $covers];
	}

	/**
	 * @param string $test
	 * @param TestInventory $inventory
	 * @return string
	 * @throws DiscoveryException when the inventory does not list the test's class
	 */
	private function fileOf(string $test, TestInventory $inventory): string {
		$class = str_contains($test, "::") ? explode("::", $test)[0] : $test;
		$file = $inventory->classes[$class] ?? "";

		if($file === "") {
			throw new DiscoveryException(
				"The coverage report credits \"{$test}\" to class \"{$class}\", which PHPUnit's test"
				. " inventory does not list. The inventory and the report came from different runs,"
				. " or the suite changed between them.",
			);
		}

		return $file;
	}

	/**
	 * **Sorted on both axes, because an identity is a hash.** Two runs of the same
	 * suite must produce byte-identical relations or every key misses, and the
	 * order coverage records arrive in is the order the tests happened to run —
	 * which `executionOrder="random"` changes on purpose.
	 *
	 * @param array<string, array<string, string>> $relation
	 * @return array<string, list<string>>
	 */
	private function sorted(array $relation): array {
		$sorted = [];

		foreach($relation as $key => $values) {
			// `sort()` reindexes, so there is nothing left for `array_values()` to do.
			$paths = $values;
			sort($paths);
			$sorted[$key] = $paths;
		}

		ksort($sorted);

		return $sorted;
	}
}
