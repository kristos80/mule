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
	 * **The path is taken as given rather than resolved.** Every absolute path
	 * mule relativises comes from a coverage report, which PHPUnit has already
	 * written resolved, and the root was resolved when it was found — so a
	 * symlink that made the two disagree is reported here instead of silently
	 * keying a file twice.
	 *
	 * @param string $absolute
	 * @return string
	 */
	public function relative(string $absolute): string {
		$prefix = "{$this->root}/";

		if(!str_starts_with($absolute, $prefix)) {
			throw new RuntimeException("{$absolute} is outside the project root {$this->root}, so mule cannot record it.");
		}

		return substr($absolute, strlen($prefix));
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
