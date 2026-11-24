<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Coverage\Unreached;

/**
 * Every line mule prints.
 *
 * Deciding what to say and saying it are different acts: these return strings,
 * so what mule reports is asserted like any other answer rather than captured
 * from a stream.
 */
final readonly class Summary {

	public const string USAGE = "usage: mule new|full|status";

	/**
	 * What the store vouches for.
	 *
	 * @param int $total Every file the coverage report knows about.
	 * @param int $stale Proven before, but not against what is there now.
	 * @param int $unproven Not covered in full, so never bankable.
	 * @return string
	 */
	public static function verified(int $total, int $stale, int $unproven): string {
		$verified = $total - $stale - $unproven;
		$uncovered = $unproven === 0 ? "" : ", {$unproven} not covered in full";

		return "mule: {$verified} of {$total} files verified, {$stale} stale{$uncovered}.";
	}

	/**
	 * Where the tests stop, for the files that cannot be banked because of it.
	 *
	 * **Said out loud rather than left to be inferred.** A file that is not
	 * covered in full is re-mutated on every run and never recorded, so
	 * without this it would sit in the stale count for good with no reason
	 * attached to it.
	 *
	 * @param array<string, list<Unreached>> $gaps Project-relative path to its unreached ranges.
	 * @return list<string> One line, then one per file; empty when there is no gap.
	 */
	public static function unreached(array $gaps): array {
		if($gaps === []) {
			return [];
		}

		$lines = ["mule: not banked until the tests reach them:"];

		ksort($gaps);

		foreach($gaps as $path => $unreached) {
			$lines[] = "  {$path} — " . self::described($unreached);
		}

		return $lines;
	}

	/**
	 * @param list<Unreached> $unreached
	 * @return string
	 */
	private static function described(array $unreached): string {
		return $unreached === []
			? "the report gives no method ranges"
			: implode("; ", array_map(static fn(Unreached $one): string => $one->described(), $unreached));
	}

	/**
	 * @return string
	 */
	public static function nothingToMutate(): string {
		return "mule: nothing to mutate, every file is verified against its own tests.";
	}

	/**
	 * @param int $files
	 * @param string $store
	 * @return string
	 */
	public static function banked(int $files, string $store): string {
		return "mule: {$files} file(s) recorded at the full score in {$store}.";
	}

	/**
	 * A failure mule predicted: something about the project, its configuration
	 * or its filesystem that it cannot work with.
	 *
	 * @param string $message
	 * @return string
	 */
	public static function failed(string $message): string {
		return "mule: {$message}";
	}

	/**
	 * A fault in mule itself, which is a different thing to say.
	 *
	 * Everything mule throws on purpose is a {@see \Kristos80\Mule\Failure};
	 * anything else reaching the top is a bug here rather than a mistake in the
	 * project being gated, and saying so is the difference between a user
	 * checking their `mule.json` and a user filing an issue.
	 *
	 * @param string $class
	 * @param string $message
	 * @return string
	 */
	public static function crashed(string $class, string $message): string {
		return "mule: {$class}: {$message} — that is a fault in mule rather than in your project, so please report it.";
	}
}
