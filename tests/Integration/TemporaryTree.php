<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A directory that exists for one test and is gone afterwards.
 *
 * The integration suite is the only code in the repository that touches a disk,
 * so it is also the only code that can leave something behind. This is what stops
 * it.
 */
trait TemporaryTree {

	/**
	 * @var non-empty-string
	 */
	private string $tree;

	protected function setUp(): void {
		$this->tree = sys_get_temp_dir() . "/mule-integration-" . bin2hex(random_bytes(8));

		mkdir($this->tree, 0o700);
	}

	protected function tearDown(): void {
		if(!is_dir($this->tree)) {
			return;
		}

		// The iterator's *keys* are the pathnames, which arrive as strings
		// rather than as `mixed` SplFileInfo objects — so there is nothing to
		// narrow and no inline `@var` standing in for a type.
		$entries = array_keys(iterator_to_array(new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->tree, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		)));

		foreach($entries as $path) {
			is_dir($path) ? rmdir($path) : unlink($path);
		}

		rmdir($this->tree);
	}

	/**
	 * @param string $relative
	 * @return non-empty-string
	 */
	private function at(string $relative): string {
		return "{$this->tree}/{$relative}";
	}

	/**
	 * A file's bytes, or a failure. The integration suite reads files it has
	 * just written, so `FALSE` here means the test is wrong rather than the
	 * code — and it has to be a throw, because `string|false` cannot be handed
	 * to an assertion.
	 *
	 * @param string $path
	 * @return string
	 */
	private function contents(string $path): string {
		$contents = file_get_contents($path);

		if($contents === FALSE) {
			self::fail("The test expected to read {$path}.");
		}

		return $contents;
	}
}
