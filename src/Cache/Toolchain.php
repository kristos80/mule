<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use function hash;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * Everything outside the project that decides what a verdict means.
 *
 * **A verdict is reusable only under an equivalent verification environment.**
 * The same bytes mutated by a different engine, measured by a different driver,
 * or gated by a different mutator set are not the same claim — so all of it is
 * digested, and a change to any of it invalidates the whole store at once. That
 * is correct rather than an error: nothing in the store was proven under the new
 * arrangement.
 *
 * **The engine's configuration is in here because mule renders it**, which is
 * the other half of why a consumer's own `infection.json5` is refused rather
 * than ignored (§4). A configuration mule did not write is a configuration mule
 * cannot digest, and every key that would defeat a guard lives in one.
 */
final readonly class Toolchain {

	/**
	 * @param string $lock The digest of `composer.lock`: the engine and the runner, pinned.
	 * @param string $phpunit The digest of the project's PHPUnit configuration — it decides what is instrumented.
	 * @param string $infection The digest of the configuration mule rendered for the engine.
	 * @param list<string> $mutators The resolved mutator set, which decides which mutants exist at all.
	 * @param string $php `PHP_VERSION`.
	 * @param string $driver The coverage driver and its version.
	 * @param string $mule mule's own version.
	 * @return non-empty-string
	 */
	public static function of(
		string $lock,
		string $phpunit,
		string $infection,
		array $mutators,
		string $php,
		string $driver,
		string $mule,
	): string {
		$sorted = $mutators;
		sort($sorted);

		return self::digest([
			"lock" => $lock,
			"phpunit" => $phpunit,
			"infection" => $infection,
			"mutators" => $sorted,
			"php" => $php,
			"driver" => $driver,
			"mule" => $mule,
		]);
	}

	/**
	 * **Hashed over a structure, not a joined string.** Any separator is a
	 * character some input can contain, and two different toolchains that join
	 * to the same string would share a key — which is the one failure a digest
	 * exists to prevent. JSON has the escaping already.
	 *
	 * @param array<string, mixed> $parts
	 * @return non-empty-string
	 */
	public static function digest(array $parts): string {
		// `JSON_THROW_ON_ERROR` either throws or answers a non-empty string, so
		// there is no `FALSE` to guard and nothing an empty-string check could
		// ever catch. The analyser said so before the gate could.
		return hash("sha256", json_encode($parts, JSON_THROW_ON_ERROR));
	}
}
