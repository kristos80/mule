<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\Failure;

/**
 * The one place a test-result map stops being `mixed`.
 *
 * **Two things decode one**: a `.cov` payload off disk and a shard out of the
 * cache. Narrowing in both would be the same rules written twice, and the first
 * time they disagreed the cache and the capture would describe different runs.
 *
 * The three fields are what `php-code-coverage` writes into `index.xml`, and
 * `time` is the one worth getting right: a wrong time is how a mutant comes to
 * look too slow to run, and a skipped mutant is counted in no detail array and
 * dropped from MSI's denominator (M12, M14).
 *
 * Measured at 0.66 ms for the 4,393 entries of a real suite, which is not a
 * reason to narrow later or less.
 */
final readonly class TestResults {

	/**
	 * @param array<array-key, mixed> $results
	 * @param string $what Named in the refusal, so a bad capture and a bad shard read differently.
	 * @return array<non-empty-string, array{size: string, status: string, time: float}>
	 */
	public static function of(array $results, string $what): array {
		$narrowed = [];

		foreach($results as $id => $result) {
			$narrowed[self::id($id, $what)] = self::one($id, $result, $what);
		}

		return $narrowed;
	}

	/**
	 * @param array-key $id
	 * @param string $what
	 * @return non-empty-string
	 */
	private static function id(mixed $id, string $what): string {
		if(!is_string($id) || $id === "") {
			throw new Failure("{$what} has an entry under a key that is not a test id.");
		}

		return $id;
	}

	/**
	 * @param array-key $id
	 * @param mixed $result
	 * @param string $what
	 * @return array{size: string, status: string, time: float}
	 */
	private static function one(mixed $id, mixed $result, string $what): array {
		if(
			!is_array($result)
			|| !is_string($result["size"] ?? NULL)
			|| !is_string($result["status"] ?? NULL)
			|| !is_float($result["time"] ?? NULL)
		) {
			throw new Failure("{$what} gives \"{$id}\" something other than a size, a status and a time.");
		}

		return ["size" => $result["size"], "status" => $result["status"], "time" => $result["time"]];
	}
}
