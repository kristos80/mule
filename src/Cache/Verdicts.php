<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Json\Decoded;
use RuntimeException;

/**
 * A verdict per source file: either what the tree says right now, or what the
 * store recorded.
 *
 * Comparing two of these is the whole decision mule makes.
 */
final readonly class Verdicts {

	/**
	 * @param array<string, Verdict> $verdicts Project-relative path to verdict, sorted by path.
	 */
	private function __construct(
		private array $verdicts,
	) {}

	/**
	 * @param array<string, Verdict> $verdicts
	 * @return self
	 */
	public static function of(array $verdicts): self {
		ksort($verdicts);

		return new self($verdicts);
	}

	/**
	 * A set read back from the store.
	 *
	 * @param string $json
	 * @return self
	 */
	public static function fromJson(string $json): self {
		$verdicts = [];

		foreach(Decoded::of($json, "the mule store")->entries() as $path => $entry) {
			$verdicts[$path] = new Verdict($entry->string("source"), $entry->string("tests"));
		}

		return self::of($verdicts);
	}

	/**
	 * @return list<string>
	 */
	public function paths(): array {
		return array_keys($this->verdicts);
	}

	/**
	 * @return int
	 */
	public function count(): int {
		return count($this->verdicts);
	}

	/**
	 * The files in this set whose verdict the stored set cannot vouch for:
	 * never recorded, or recorded against a source or a covering test that has
	 * changed since.
	 *
	 * Direction matters. This set is the universe — the files the coverage
	 * report knows about — and the stored set only ever answers questions about
	 * them. A path the store holds and the report does not is a deleted or
	 * newly excluded file, and nothing needs mutating on its behalf.
	 *
	 * @param self $stored
	 * @return list<string>
	 */
	public function staleAgainst(self $stored): array {
		$stale = [];

		foreach($this->verdicts as $path => $verdict) {
			$recorded = $stored->verdicts[$path] ?? NULL;

			if($recorded === NULL || !$recorded->matches($verdict)) {
				$stale[] = $path;
			}
		}

		return $stale;
	}

	/**
	 * This set with another's entries written over it.
	 *
	 * @param self $other
	 * @return self
	 */
	public function merged(self $other): self {
		return self::of([...$this->verdicts, ...$other->verdicts]);
	}

	/**
	 * Just the named files, which is what a narrowed run banks.
	 *
	 * @param list<string> $paths
	 * @return self
	 */
	public function only(array $paths): self {
		$verdicts = [];

		foreach($paths as $path) {
			$verdicts[$path] = $this->verdicts[$path]
				?? throw new RuntimeException("{$path} is not in this run's verdicts, so mule cannot bank it.");
		}

		return self::of($verdicts);
	}

	/**
	 * The set as it is written: sorted, one entry per line, so that a rebase
	 * conflict in a committed store is mechanical to resolve.
	 *
	 * @return string
	 */
	public function toJson(): string {
		$lines = [];

		foreach($this->verdicts as $path => $verdict) {
			$lines[] = "\t" . self::encoded($path) . ": " . self::encoded(["source" => $verdict->source, "tests" => $verdict->tests]);
		}

		return $lines === [] ? "{}\n" : "{\n" . implode(",\n", $lines) . "\n}\n";
	}

	/**
	 * One value as the store spells it: slashes as they were written, because a
	 * store full of `src\/Cache` is for a machine to read and this one is for a
	 * person resolving a conflict in it.
	 *
	 * @param string|array<string, string> $value
	 * @return string
	 */
	private static function encoded(string|array $value): string {
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
