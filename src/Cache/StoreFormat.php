<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Failure;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The store on disk, read and written.
 *
 * **Its own class because validating is most of the work and none of the
 * meaning**, the same reason `ShardFormat` exists. `Snapshot` is what the store
 * says; this is what it takes to trust one off disk.
 *
 * **Refuses rather than repairs.** A store mule cannot read is a store it must
 * not half-believe: the alternative to a refusal is a default, and every default
 * here is a verdict nobody earned.
 */
final readonly class StoreFormat {

	/**
	 * Committed, and byte-identical on every machine that would compute it — so
	 * pretty-printed with unescaped slashes, because a store a person cannot read
	 * in a diff is a store nobody reviews.
	 *
	 * @param Snapshot $snapshot
	 * @return string
	 */
	public static function write(Snapshot $snapshot): string {
		$verdicts = [];

		foreach($snapshot->verdicts as $path => $verdict) {
			$verdicts[$path] = ["key" => $verdict->key, "mutants" => $verdict->mutants];
		}

		return json_encode([
			"toolchain" => $snapshot->toolchain,
			"verdicts" => $verdicts,
			"unproven" => $snapshot->unproven,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	/**
	 * @param mixed $decoded
	 * @return Snapshot
	 */
	public static function read(mixed $decoded): Snapshot {
		if(!is_array($decoded)) {
			throw new Failure("A store must be an array, " . get_debug_type($decoded) . " given.");
		}

		foreach(["toolchain", "verdicts", "unproven"] as $key) {
			if(!array_key_exists($key, $decoded)) {
				throw new Failure("A store is missing its \"{$key}\" section.");
			}
		}

		$toolchain = $decoded["toolchain"];

		if(!is_string($toolchain) || $toolchain === "") {
			throw new Failure("A store's toolchain is not a digest, so nothing in it can be placed.");
		}

		return new Snapshot(
			self::verdicts($decoded["verdicts"]),
			self::unproven($decoded["unproven"]),
			$toolchain,
		);
	}

	/**
	 * @param mixed $value
	 * @return array<string, Verdict>
	 */
	private static function verdicts(mixed $value): array {
		if(!is_array($value)) {
			throw new Failure("A store's verdicts must be an array, " . get_debug_type($value) . " given.");
		}

		$verdicts = [];

		foreach($value as $path => $verdict) {
			if(!is_string($path) || $path === "") {
				throw new Failure("A store has a verdict under something that is not a file path.");
			}

			$verdicts[$path] = self::verdict($path, $verdict);
		}

		// Canonical order is `Snapshot`'s, which every path out of here goes
		// through — a second sort would be a second place to get it wrong.
		return $verdicts;
	}

	/**
	 * **A verdict with no key is the dangerous shape**, not a malformed one: it
	 * would hold against anything, so every file would read as proven for ever.
	 *
	 * @param string $path
	 * @param mixed $verdict
	 * @return Verdict
	 */
	private static function verdict(string $path, mixed $verdict): Verdict {
		if(!is_array($verdict)) {
			throw new Failure("The verdict for \"{$path}\" is not a key and a count.");
		}

		$key = $verdict["key"] ?? NULL;
		$mutants = $verdict["mutants"] ?? NULL;

		if(!is_string($key) || $key === "") {
			throw new Failure("The verdict for \"{$path}\" rests on no key, so it would hold against anything.");
		}

		if(!is_int($mutants) || $mutants < 0) {
			throw new Failure("The verdict for \"{$path}\" counts something other than mutants.");
		}

		return new Verdict($key, $mutants);
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private static function unproven(mixed $value): array {
		if(!is_array($value)) {
			throw new Failure("A store's unproven list must be an array, " . get_debug_type($value) . " given.");
		}

		$unproven = [];

		foreach($value as $path) {
			if(!is_string($path) || $path === "") {
				throw new Failure("A store's unproven list includes something that is not a file path.");
			}

			$unproven[] = $path;
		}

		return $unproven;
	}
}
