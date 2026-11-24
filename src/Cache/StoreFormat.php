<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Coverage\Unreached;
use Kristos80\Mule\Json\Decoded;

/**
 * The bytes a snapshot is kept as, and back again.
 *
 * **Its own class because the shape of the file is not the shape of the
 * decision.** A snapshot answers what is stale; this answers what a person
 * sees in a diff — sorted, one entry per line, slashes as they were written,
 * five named sections — and the two change for entirely different reasons.
 */
final readonly class StoreFormat {

	/**
	 * A snapshot read back from the store.
	 *
	 * A store written by an older mule has none of these sections and reads as
	 * empty, so the next run proves everything once. Bytes that are not a JSON
	 * object at all still stop the run: an older shape is not damage, and a
	 * truncated file is.
	 *
	 * **A store written before `configuration` was a section of its own reads
	 * as having none**, which is the conservative answer rather than a special
	 * case: {@see Freshness} compares the recorded configuration against the
	 * declared files as they are now, and no configuration cannot match a tree
	 * that has some — so such a store re-proves itself once and is written back
	 * in the current shape.
	 *
	 * @param string $json
	 * @return Snapshot
	 */
	public static function read(string $json): Snapshot {
		$store = Decoded::of($json, "the mule store");
		$verified = [];
		$tests = [];
		$watched = [];
		$configuration = [];

		foreach($store->object("verified")->entries() as $path => $entry) {
			$verified[$path] = $entry->string("digest");
		}

		foreach($store->object("tests")->entries() as $path => $entry) {
			$tests[$path] = new Touched($entry->string("source"), $entry->strings("touches"));
		}

		foreach($store->object("watched")->entries() as $path => $entry) {
			$watched[$path] = $entry->string("digest");
		}

		foreach($store->object("configuration")->entries() as $path => $entry) {
			$configuration[$path] = $entry->string("digest");
		}

		return Snapshot::of($verified, $tests, self::unprovenIn($store), $watched, $configuration);
	}

	/**
	 * The snapshot as it is written: sorted, one entry per line, so that a
	 * rebase conflict in a committed store is mechanical to resolve.
	 *
	 * @param Snapshot $snapshot
	 * @return string
	 */
	public static function write(Snapshot $snapshot): string {
		$verified = [];
		$tests = [];
		$unproven = [];
		$watched = [];
		$configuration = [];

		foreach($snapshot->verified() as $path => $digest) {
			$verified[] = self::line($path, ["digest" => $digest]);
		}

		foreach($snapshot->unproven() as $path => $entry) {
			$unproven[] = self::line($path, $entry->toArray());
		}

		foreach($snapshot->tests() as $path => $test) {
			$tests[] = self::line($path, ["source" => $test->source, "touches" => $test->touches]);
		}

		foreach($snapshot->watched() as $path => $digest) {
			$watched[] = self::line($path, ["digest" => $digest]);
		}

		foreach($snapshot->configuration() as $path => $digest) {
			$configuration[] = self::line($path, ["digest" => $digest]);
		}

		return "{\n\t\"verified\": {" . self::block($verified)
			. "},\n\t\"unproven\": {" . self::block($unproven)
			. "},\n\t\"tests\": {" . self::block($tests)
			. "},\n\t\"watched\": {" . self::block($watched)
			. "},\n\t\"configuration\": {" . self::block($configuration) . "}\n}\n";
	}

	/**
	 * The unproven section, read back.
	 *
	 * @param Decoded $store
	 * @return array<string, Unproven>
	 */
	private static function unprovenIn(Decoded $store): array {
		$unproven = [];

		foreach($store->object("unproven")->entries() as $path => $entry) {
			$unreached = [];

			foreach($entry->objects("unreached") as $gap) {
				$unreached[] = new Unreached(
					$gap->string("name"),
					$gap->number("from"),
					$gap->number("to"),
					$gap->number("lines"),
				);
			}

			$unproven[$path] = new Unproven($entry->string("digest"), $unreached);
		}

		return $unproven;
	}

	/**
	 * @param string $path
	 * @param array<string, mixed> $entry
	 * @return string
	 */
	private static function line(string $path, array $entry): string {
		return "\t\t" . self::encoded($path) . ": " . self::encoded($entry);
	}

	/**
	 * One section's lines, or nothing between the braces where it has none.
	 *
	 * @param list<string> $lines
	 * @return string
	 */
	private static function block(array $lines): string {
		return $lines === [] ? "" : "\n" . implode(",\n", $lines) . "\n\t";
	}

	/**
	 * Slashes as they were written, because a store full of `src\/Cache` is
	 * for a machine to read and this one is for a person resolving a conflict
	 * in it.
	 *
	 * @param string|array<string, mixed> $value
	 * @return string
	 */
	private static function encoded(string|array $value): string {
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
