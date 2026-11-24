<?php
declare(strict_types=1);

namespace Kristos80\Mule;

use Kristos80\Mule\External\FilesystemInterface;
use RuntimeException;

/**
 * The project mule is caching verdicts for, and the one thing every path in and
 * out of the store is relative to.
 *
 * **Paths are stored relative to this root, never absolute.** The store is
 * committed, so the machine that reads it is usually not the machine that wrote
 * it: an absolute path would make every entry miss on CI. This is the single
 * difference a mutation engine's own cache cannot offer — both of Pest's live
 * under `vendor/` and key on `realpath()`.
 */
final readonly class Project {

	/**
	 * @param string $root An absolute, symlink-resolved path with no trailing separator.
	 */
	private function __construct(
		public string $root,
	) {}

	/**
	 * The project a directory belongs to, found by walking up to the nearest
	 * `composer.json`.
	 *
	 * @param string $directory
	 * @param FilesystemInterface $filesystem
	 * @return self
	 */
	public static function containing(string $directory, FilesystemInterface $filesystem): self {
		foreach(self::selfAndAncestorsOf($filesystem->resolve($directory)) as $candidate) {
			// The last candidate is the root itself, whose own separator is
			// already there; every other one needs it.
			if($filesystem->exists(rtrim($candidate, "/") . "/composer.json")) {
				return new self($candidate);
			}
		}

		throw new RuntimeException("No composer.json at or above {$directory}: mule needs a project root.");
	}

	/**
	 * A directory and every directory above it, nearest first.
	 *
	 * **The walk is counted out in advance rather than stopped when it runs out
	 * of parents.** A loop that ends by noticing it has reached the root ends
	 * *only* there, so a mutant that removes the noticing runs forever and is
	 * killed by the clock rather than by an assertion — the one verdict in a
	 * mutation report that says nothing about the tests. A path has exactly as
	 * many directories above it as it has separators, which is a number, and a
	 * loop over a number cannot fail to end.
	 *
	 * @param string $directory An absolute path.
	 * @return list<string>
	 */
	private static function selfAndAncestorsOf(string $directory): array {
		$candidates = [$directory];
		$steps = substr_count($directory, "/");

		for($step = 0; $step < $steps; $step++) {
			$candidates[] = dirname($candidates[$step]);
		}

		return $candidates;
	}

	/**
	 * An absolute path as the store records it.
	 *
	 * **`.` and `..` segments are applied, and nothing else is resolved.** Not
	 * every path reaching here comes from a coverage report: half of each key is
	 * a digest over the paths of the test files covering a source file, and
	 * those come from the project's Composer autoloader, which answers with the
	 * directory as its own generated configuration spells it — a psr-4 prefix
	 * mapped to `tests` comes back as `{root}/vendor/composer/../../tests/Unit/FooTest.php`.
	 * It names the right file, so the digest of its contents was never in doubt,
	 * but the path is part of the key: left as given it records a store no other
	 * reading of the same repository can be compared with, and one that
	 * invalidates itself wholesale the day the vendor directory moves.
	 *
	 * **Applying them is lexical, so a symlink still disagrees out loud.** The
	 * root was symlink-resolved when it was found and a coverage report's paths
	 * are written resolved, so one that is not is still reported here rather
	 * than keying the same file twice. It is also what makes the containment
	 * guard mean what it says, since `{root}/vendor/composer/../../../elsewhere`
	 * starts with the root as a string while naming a file outside it.
	 *
	 * @param string $absolute
	 * @return string
	 */
	public function relative(string $absolute): string {
		$path = self::applied($absolute);
		$prefix = "{$this->root}/";

		if(!str_starts_with($path, $prefix)) {
			throw new RuntimeException("{$path} is outside the project root {$this->root}, so mule cannot record it.");
		}

		return substr($path, strlen($prefix));
	}

	/**
	 * An absolute path with every `.` and `..` segment applied.
	 *
	 * @param string $absolute
	 * @return string
	 */
	private static function applied(string $absolute): string {
		$segments = [];

		foreach(explode("/", $absolute) as $segment) {
			$segments = self::segment($segments, $segment);
		}

		return "/" . implode("/", $segments);
	}

	/**
	 * One segment applied to the path built out of the ones before it.
	 *
	 * A `..` with nothing to its left is dropped rather than kept: the path is
	 * absolute, so there is nothing above its first segment to climb to.
	 *
	 * @param list<string> $segments
	 * @param string $segment
	 * @return list<string>
	 */
	private static function segment(array $segments, string $segment): array {
		if($segment === "..") {
			array_pop($segments);

			return $segments;
		}

		return $segment === "" || $segment === "." ? $segments : [...$segments, $segment];
	}

	/**
	 * A recorded path back on this machine.
	 *
	 * @param string $relative
	 * @return string
	 */
	public function absolute(string $relative): string {
		return "{$this->root}/{$relative}";
	}
}
