<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

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
	 * @param int $total
	 * @param int $stale
	 * @return string
	 */
	public static function verified(int $total, int $stale): string {
		$verified = $total - $stale;

		return "mule: {$verified} of {$total} files verified, {$stale} stale.";
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
