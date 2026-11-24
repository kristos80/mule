<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\External\ClassesInterface;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Project;

/**
 * Every PHP file the project autoloads and owns.
 *
 * **Owns is the operative word.** The autoloader knows every prefix registered
 * in the process, which includes one per installed dependency, and recording
 * those would put a digest of `vendor/` in a committed store: fifteen hundred
 * entries here, invalidated by any `composer install`, and different on a
 * machine that resolved different versions. A store that cannot survive the
 * trip to CI is the one thing this cache may not be.
 *
 * So the universe is what the autoloader declares, minus anything under
 * `vendor/` and anything outside the project — a prefix may legitimately point
 * at either, and neither is the project's own code.
 */
final readonly class Universe {

	private const string VENDOR = "vendor/";

	/**
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @param ClassesInterface $classes
	 */
	public function __construct(
		private Project $project,
		private FilesystemInterface $filesystem,
		private ClassesInterface $classes,
	) {}

	/**
	 * @return list<string> Project-relative paths, in no particular order.
	 */
	public function paths(): array {
		$paths = [];

		foreach($this->classes->directories() as $directory) {
			foreach($this->ownedIn($directory) as $path) {
				$paths[] = $path;
			}
		}

		return $paths;
	}

	/**
	 * A declared directory that is not there is skipped rather than fatal: a
	 * `composer.json` may name one.
	 *
	 * @param string $directory
	 * @return list<string>
	 */
	private function ownedIn(string $directory): array {
		$paths = [];
		$entries = $this->filesystem->isDirectory($directory) ? $this->filesystem->entriesUnder($directory) : [];

		foreach($entries as $entry) {
			if($this->isOwned($entry)) {
				$paths[] = $this->project->relative($entry);
			}
		}

		return $paths;
	}

	/**
	 * @param string $entry
	 * @return bool
	 */
	private function isOwned(string $entry): bool {
		return str_ends_with($entry, ".php")
			&& $this->project->contains($entry)
			&& !str_starts_with($this->project->relative($entry), self::VENDOR);
	}
}
