<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\Failure;

/**
 * A stored shard, read back.
 *
 * **Its own class because validating is most of the work and none of the
 * meaning.** `Shard` is what a shard *is*; this is what it takes to trust one
 * off disk. Together they put the class over the blueprint's complexity limit,
 * which is the limit doing its job.
 *
 * **Deep rather than shallow, because this is the attribution itself.** A line
 * number that is not a line, or an index that is not a position in the test
 * list, is coverage credited to the wrong place — and nothing downstream would
 * notice. A digest that is not a digest is a shard reused when it should not be,
 * which is the one failure the record exists to prevent.
 */
final readonly class ShardFormat {

	/**
	 * Reads one decoded shard, refusing anything it cannot vouch for.
	 *
	 * @param mixed $decoded
	 * @return array{
	 *     testClass: non-empty-string,
	 *     testIds: array<int<0, max>, non-empty-string>,
	 *     lines: array<non-empty-string, array<int<1, max>, list<int<0, max>>>>,
	 *     testResults: array<non-empty-string, array{size: string, status: string, time: float}>,
	 *     digests: array<string, non-empty-string>,
	 * }
	 */
	public static function read(mixed $decoded): array {
		if(!is_array($decoded)) {
			throw new Failure("A shard must be an array, " . get_debug_type($decoded) . " given.");
		}

		foreach(["testClass", "testIds", "lines", "testResults", "digests"] as $key) {
			if(!array_key_exists($key, $decoded)) {
				throw new Failure("A shard is missing its \"{$key}\" key.");
			}
		}

		if(!is_string($decoded["testClass"]) || $decoded["testClass"] === "") {
			throw new Failure("A shard's \"testClass\" is not a class name.");
		}

		return [
			"testClass" => $decoded["testClass"],
			"testIds" => self::testIds($decoded["testIds"]),
			"lines" => self::lines($decoded["lines"]),
			"testResults" => TestResults::of(self::arrayOf($decoded["testResults"], "test results"), "A shard's test results"),
			"digests" => self::digests($decoded["digests"]),
		];
	}

	/**
	 * **Positional and non-empty, both checked.** The line data holds positions
	 * in this list, so a list with holes would attribute coverage to the wrong
	 * test — M3's failure mode, arriving through the cache instead of through a
	 * split — and an empty id would name no test at all.
	 *
	 * @param mixed $value
	 * @return array<int<0, max>, non-empty-string>
	 */
	private static function testIds(mixed $value): array {
		$ids = self::listOf($value, "test ids");
		$checked = [];

		foreach($ids as $id) {
			if(!is_string($id) || $id === "") {
				throw new Failure("A shard's test ids include one that is not a test id.");
			}

			$checked[] = $id;
		}

		return $checked;
	}

	/**
	 * The stored line data, checked down to the indices.
	 *
	 * Deep rather than shallow because this is the attribution itself: a line
	 * number that is not a line, or an index that is not a position in the test
	 * list, is coverage credited to the wrong place — and nothing downstream
	 * would notice.
	 *
	 * @param mixed $value
	 * @return array<non-empty-string, array<int<1, max>, list<int<0, max>>>>
	 */
	private static function lines(mixed $value): array {
		$files = self::arrayOf($value, "line data");
		$checked = [];

		foreach($files as $file => $numbers) {
			if(!is_string($file) || $file === "") {
				throw new Failure("A shard's line data is keyed by something that is not a file path.");
			}

			$checked[$file] = self::numbers($file, $numbers);
		}

		return $checked;
	}

	/**
	 * @param string $file
	 * @param mixed $numbers
	 * @return array<int<1, max>, list<int<0, max>>>
	 */
	private static function numbers(string $file, mixed $numbers): array {
		$checked = [];

		foreach(self::arrayOf($numbers, "line data for \"{$file}\"") as $line => $indices) {
			if(!is_int($line) || $line < 1) {
				throw new Failure("A shard credits \"{$file}\" at something that is not a line number.");
			}

			$checked[$line] = self::indices($file, $line, $indices);
		}

		return $checked;
	}

	/**
	 * @param string $file
	 * @param int $line
	 * @param mixed $indices
	 * @return list<int<0, max>>
	 */
	private static function indices(string $file, int $line, mixed $indices): array {
		$checked = [];

		foreach(self::listOf($indices, "the tests covering \"{$file}\" line {$line}") as $index) {
			if(!is_int($index) || $index < 0) {
				throw new Failure("A shard credits \"{$file}\" line {$line} to something that is not a test index.");
			}

			$checked[] = $index;
		}

		return $checked;
	}

	/**
	 * **Every digest, because every one of them is the reuse rule.** A digest
	 * that is not a digest is a shard that would be reused when it should not
	 * be, which is the one failure this class exists to prevent.
	 *
	 * @param mixed $value
	 * @return array<string, non-empty-string>
	 */
	private static function digests(mixed $value): array {
		$digests = self::arrayOf($value, "digests");
		$checked = [];

		foreach($digests as $path => $digest) {
			if(!is_string($path) || !is_string($digest) || $digest === "") {
				throw new Failure("A shard's digests include one that is not a path and a digest.");
			}

			$checked[$path] = $digest;
		}

		return $checked;
	}

	/**
	 * @param mixed $value
	 * @param string $what
	 * @return array<array-key, mixed>
	 */
	private static function arrayOf(mixed $value, string $what): array {
		if(!is_array($value)) {
			throw new Failure("A shard's {$what} must be an array, " . get_debug_type($value) . " given.");
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @param string $what
	 * @return list<mixed>
	 */
	private static function listOf(mixed $value, string $what): array {
		$array = self::arrayOf($value, $what);

		if(!array_is_list($array)) {
			throw new Failure("A shard's {$what} must be a list, which decides what its line data indexes into.");
		}

		return $array;
	}
}
