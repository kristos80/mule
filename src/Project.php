<?php
declare(strict_types=1);

namespace Kristos80\Mule;

use Kristos80\Mule\External\FilesystemInterface;

/**
 * One checkout, and the paths inside it.
 *
 * **Every path in the store is project-relative**, because an absolute one is
 * machine-local state and the store is committed. A verdict keyed on
 * `/home/ci/build/src/A.php` is a verdict no laptop can use, and the point of
 * committing the store is that the next machine inherits the work.
 *
 * It also resolves, which is not optional: Composer answers `findFile()` with
 * paths like `…/vendor/composer/../../tests/X.php`, and a key taken over two
 * spellings of one file is two keys for one verdict.
 */
final readonly class Project {

	/**
	 * @param FilesystemInterface $filesystem
	 * @param string $root The checkout's directory, already resolved.
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private string $root,
	) {}

	/**
	 * A path inside the project, as the store spells it.
	 *
	 * @param string $path Absolute, or relative to wherever the caller got it.
	 * @return string
	 */
	public function relative(string $path): string {
		$resolved = $this->filesystem->resolve($path);
		$prefix = "{$this->root}/";

		if(!str_starts_with($resolved, $prefix)) {
			throw new Failure("{$resolved} is outside the project at {$this->root}.");
		}

		return substr($resolved, strlen($prefix));
	}

	/**
	 * @param string $relative
	 * @return string
	 */
	public function absolute(string $relative): string {
		return "{$this->root}/{$relative}";
	}
}
