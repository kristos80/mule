<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Closure;
use FilesystemIterator;
use Kristos80\Mule\Failure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Override;
use const LOCK_EX;
use const LOCK_UN;

/**
 * The filesystem, as PHP offers it.
 *
 * **One primitive per method, plus a guard that throws.** Nothing here decides
 * anything, so there is nothing to unit test and nothing a mutant could change
 * that an assertion would notice — which is why this file is the only one
 * excluded from coverage and from mutation, named in both `phpunit.xml` and
 * `infection.json5` so the two lists stay visibly in step. Everything moved out
 * of here is gated at 100 %.
 *
 * The `@` is on the primitives that announce a failure twice, once as a warning
 * and once as `FALSE`. The warning is the copy mule has no use for; the `FALSE`
 * becomes an exception carrying the path.
 */
final readonly class PhpFilesystem implements FilesystemInterface {

	#[Override]
	public function read(string $path): string {
		$contents = @file_get_contents($path);

		if($contents === FALSE) {
			throw new Failure("Cannot read {$path}.");
		}

		return $contents;
	}

	#[Override]
	public function write(string $path, string $contents): void {
		if(@file_put_contents($path, $contents) === FALSE) {
			throw new Failure("Cannot write {$path}.");
		}
	}

	#[Override]
	public function exists(string $path): bool {
		return file_exists($path);
	}

	#[Override]
	public function remove(string $path): void {
		if(file_exists($path) && !@unlink($path)) {
			throw new Failure("Cannot remove {$path}.");
		}
	}

	#[Override]
	public function isDirectory(string $path): bool {
		return is_dir($path);
	}

	#[Override]
	public function createDirectory(string $path): void {
		if(!@mkdir($path, 0o777, TRUE)) {
			throw new Failure("Cannot create the directory {$path}.");
		}
	}

	/**
	 * The iterator's *keys* are the pathnames, which arrive as strings rather
	 * than as `SplFileInfo` objects — so there is nothing to narrow and no
	 * inline type standing in for a check. Sorting is the caller's concern
	 * nowhere else, so it happens here: a tree walk's order is the filesystem's
	 * business and mule's decisions must not depend on it.
	 *
	 * @param string $directory
	 * @return list<string>
	 */
	#[Override]
	public function entriesUnder(string $directory): array {
		$entries = array_keys(iterator_to_array(new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		)));

		sort($entries);

		return $entries;
	}

	#[Override]
	public function rename(string $from, string $to): void {
		if(!@rename($from, $to)) {
			throw new Failure("Cannot move {$from} to {$to}.");
		}
	}

	/**
	 * `c` rather than `w`, so an existing lock file keeps its bytes — it has
	 * none, and truncating it would be a write to a file another process is
	 * holding.
	 *
	 * @template T
	 * @param string $path
	 * @param Closure(): T $critical
	 * @return T
	 */
	#[Override]
	public function underLock(string $path, Closure $critical): mixed {
		$handle = @fopen($path, "c");

		if($handle === FALSE) {
			throw new Failure("Cannot open {$path} to lock it.");
		}

		if(!flock($handle, LOCK_EX)) {
			throw new Failure("Cannot take an exclusive lock on {$path}.");
		}

		$result = $critical();

		flock($handle, LOCK_UN);
		fclose($handle);

		return $result;
	}

	#[Override]
	public function resolve(string $path): string {
		$resolved = realpath($path);

		if($resolved === FALSE) {
			throw new Failure("Cannot resolve {$path}: no such file or directory.");
		}

		return $resolved;
	}

	#[Override]
	public function digest(string $path): string {
		$digest = @hash_file("sha256", $path);

		if($digest === FALSE) {
			throw new Failure("Cannot hash {$path}.");
		}

		return $digest;
	}
}
