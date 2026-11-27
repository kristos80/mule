<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Closure;
use Kristos80\Mule\Failure;

/**
 * What the engine decided, read out of its JSON log.
 *
 * **The log is the verdict record, and the console is not.** Its top-level
 * sections are `stats`, `escaped`, `timeouted`, `killed`,
 * `killedByStaticAnalysis`, `errored`, `syntaxErrors`, `uncovered` and `ignored`
 * — note `uncovered` rather than `notCovered` (M6). There is no mutant ID in it,
 * so identity is derived rather than read.
 *
 * **A skipped mutant appears in none of those sections.** It was generated, it
 * is counted in `totalMutantsCount`, and it does not lower MSI — so the only
 * trace of one is `stats.skippedCount`, which is why that statistic is read
 * separately and asserted rather than summarised (M12, G2).
 */
final readonly class Outcome {

	/**
	 * The sections a mutant can be filed under. `skipped` is deliberately absent
	 * because no such section exists.
	 */
	public const array KINDS = [
		"killed",
		"escaped",
		"timeouted",
		"errored",
		"syntaxErrors",
		"uncovered",
		"ignored",
		"killedByStaticAnalysis",
	];

	/**
	 * @param array<string, array<string, int<0, max>>> $byFile Project-relative path to kind to count.
	 * @param array<string, int> $stats
	 */
	private function __construct(
		public array $byFile,
		public array $stats,
	) {}

	/**
	 * @param mixed $decoded The engine's JSON log.
	 * @param Closure(string): string $relative Turns a logged path into the store's spelling.
	 * @return self
	 */
	public static function of(mixed $decoded, Closure $relative): self {
		if(!is_array($decoded)) {
			throw new Failure("The engine's log must be an array, " . get_debug_type($decoded) . " given.");
		}

		$byFile = [];

		foreach(self::KINDS as $kind) {
			foreach(self::section($decoded, $kind) as $mutant) {
				$path = $relative(self::pathOf($mutant, $kind));
				$byFile[$path][$kind] = ($byFile[$path][$kind] ?? 0) + 1;
			}
		}

		ksort($byFile);

		return new self($byFile, self::stats($decoded));
	}

	/**
	 * How many mutants of one kind the engine filed against a file.
	 *
	 * @param string $file
	 * @param string $kind
	 * @return int<0, max>
	 */
	public function count(string $file, string $kind): int {
		return $this->byFile[$file][$kind] ?? 0;
	}

	/**
	 * Every mutant filed against a file, whatever became of it.
	 *
	 * @param string $file
	 * @return int<0, max>
	 */
	public function mutants(string $file): int {
		$mutants = 0;

		foreach($this->byFile[$file] ?? [] as $count) {
			$mutants += $count;
		}

		return $mutants;
	}

	/**
	 * **Read rather than derived.** A skipped mutant is in no section, so a count
	 * taken from the sections cannot see one — which is the whole reason G2 reads
	 * this instead.
	 *
	 * @param string $name
	 * @return int
	 */
	public function statistic(string $name): int {
		if(!array_key_exists($name, $this->stats)) {
			throw new Failure("The engine's log gives no \"{$name}\".");
		}

		return $this->stats[$name];
	}

	/**
	 * @param array<array-key, mixed> $decoded
	 * @param string $kind
	 * @return array<array-key, mixed>
	 */
	private static function section(array $decoded, string $kind): array {
		$section = $decoded[$kind] ?? [];

		if(!is_array($section)) {
			throw new Failure("The engine's log gives \"{$kind}\" as " . get_debug_type($section) . ".");
		}

		// Not reindexed: the caller only iterates it, so renumbering the keys
		// would be an operation with no result behind it.
		return $section;
	}

	/**
	 * @param mixed $mutant
	 * @param string $kind
	 * @return string
	 */
	private static function pathOf(mixed $mutant, string $kind): string {
		$mutator = is_array($mutant) ? ($mutant["mutator"] ?? NULL) : NULL;
		$path = is_array($mutator) ? ($mutator["originalFilePath"] ?? NULL) : NULL;

		if(!is_string($path) || $path === "") {
			throw new Failure("The engine filed a \"{$kind}\" mutant against no file.");
		}

		return $path;
	}

	/**
	 * @param array<array-key, mixed> $decoded
	 * @return array<string, int>
	 */
	private static function stats(array $decoded): array {
		$stats = $decoded["stats"] ?? NULL;

		if(!is_array($stats)) {
			throw new Failure("The engine's log carries no statistics, so nothing about it can be checked.");
		}

		$read = [];

		foreach($stats as $name => $value) {
			if(is_string($name) && is_int($value)) {
				$read[$name] = $value;
			}
		}

		return $read;
	}
}
