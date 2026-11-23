<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The filesystem, as PHP offers it.
 *
 * **One statement per method, plus a guard that throws.** Nothing here decides
 * anything, so there is nothing to unit test and nothing a mutant could change
 * that an assertion would notice — which is why these methods are the only ones
 * in the repository excluded from coverage and mutation, and why they are kept
 * this thin: everything moved out of here is gated at 100 %.
 *
 * The `@` is on the primitives that announce a failure twice, once as a warning
 * and once as `FALSE`. The warning is the copy mule has no use for; the `FALSE`
 * becomes an exception carrying the path.
 */
final readonly class PhpFilesystem implements FilesystemInterface {

	/**
	 * @param string $path
	 * @return bool
	 */
	public function exists(string $path): bool {
		return file_exists($path);
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public function isDirectory(string $path): bool {
		return is_dir($path);
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function read(string $path): string {
		$contents = @file_get_contents($path);

		if($contents === FALSE) {
			throw new RuntimeException("Cannot read {$path}.");
		}

		return $contents;
	}

	/**
	 * @param string $path
	 * @param string $contents
	 * @return void
	 */
	public function write(string $path, string $contents): void {
		if(@file_put_contents($path, $contents) === FALSE) {
			throw new RuntimeException("Cannot write {$path}.");
		}
	}

	/**
	 * @param string $from
	 * @param string $to
	 * @return void
	 */
	public function rename(string $from, string $to): void {
		if(!@rename($from, $to)) {
			throw new RuntimeException("Cannot move {$from} to {$to}.");
		}
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function delete(string $path): void {
		if(!@unlink($path)) {
			throw new RuntimeException("Cannot remove the file {$path}.");
		}
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function createDirectory(string $path): void {
		if(!@mkdir($path, 0o777, TRUE)) {
			throw new RuntimeException("Cannot create the directory {$path}.");
		}
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function createDirectoryExclusively(string $path): void {
		if(!@mkdir($path, 0o700)) {
			throw new RuntimeException("Cannot create the directory {$path}.");
		}
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function deleteDirectory(string $path): void {
		if(!@rmdir($path)) {
			throw new RuntimeException("Cannot remove the directory {$path}.");
		}
	}

	/**
	 * @param string $directory
	 * @return list<string>
	 */
	public function entriesUnder(string $directory): array {
		return array_keys(iterator_to_array(new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		)));
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function digest(string $path): string {
		$digest = @hash_file("sha256", $path);

		if($digest === FALSE) {
			throw new RuntimeException("Cannot hash {$path}.");
		}

		return $digest;
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function resolve(string $path): string {
		$resolved = realpath($path);

		if($resolved === FALSE) {
			throw new RuntimeException("Cannot resolve {$path}: no such directory.");
		}

		return $resolved;
	}

	/**
	 * @return string
	 */
	public function temporaryDirectory(): string {
		return sys_get_temp_dir();
	}
}
