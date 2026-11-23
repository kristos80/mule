<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A real directory on a real disk, for the only tests that want one.
 *
 * The unit suite proves what mule decides; these prove that the wrappers in
 * `src/External` reach the system they claim to. Nothing here is shared with
 * the unit suite on purpose — a temporary directory is exactly the thing the
 * rest of the tests do not have.
 */
trait TemporaryTree {

	private string $tree;

	/**
	 * @return void
	 */
	protected function createTree(): void {
		$this->tree = sys_get_temp_dir() . "/mule-integration-" . bin2hex(random_bytes(6));

		mkdir($this->tree, 0o700, TRUE);
	}

	/**
	 * @return void
	 */
	protected function removeTree(): void {
		if(!is_dir($this->tree)) {
			return;
		}

		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->tree, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach($entries as $entry) {
			$path = $entry instanceof SplFileInfo ? $entry->getPathname() : "";

			// A symlink to a directory is `is_dir()`, because that question
			// follows the link, and unlinking it is the only way to remove it:
			// rmdir() on one is ENOTDIR on Linux, which then leaves the parent
			// not empty. Darwin's rmdir tolerates it, so this only ever failed
			// in CI.
			is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
		}

		rmdir($this->tree);
	}

	/**
	 * @param string $relative
	 * @param string $contents
	 * @param int $mode
	 * @return string The absolute path written.
	 */
	protected function write(string $relative, string $contents, int $mode = 0o600): string {
		$path = "{$this->tree}/{$relative}";

		if(!is_dir(dirname($path))) {
			mkdir(dirname($path), 0o700, TRUE);
		}

		file_put_contents($path, $contents);
		chmod($path, $mode);

		return $path;
	}

	/**
	 * @param string $relative
	 * @return string
	 */
	protected function path(string $relative = ""): string {
		return $relative === "" ? $this->tree : "{$this->tree}/{$relative}";
	}
}
