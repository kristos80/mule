<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Closure;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Failure;
use Override;

/**
 * The filesystem as an array.
 *
 * The unit suite drives the whole program through this rather than through a
 * temporary directory, so no test arranges a disk and none of them can leave one
 * behind. What the real wrapper does with the same calls is the integration
 * suite's business.
 */
final class InMemoryFilesystem implements FilesystemInterface {

	/**
	 * @param array<string, string> $files
	 * @param array<string, non-empty-string> $digests
	 * @param list<string> $directories
	 * @param array<string, string> $resolved
	 * @param array<string, mixed> $payloads
	 */
	public function __construct(
		public array $files = [],
		private array $digests = [],
		public array $directories = [],
		private array $resolved = [],
		public array $payloads = [],
	) {}

	#[Override]
	public function read(string $path): string {
		if(!array_key_exists($path, $this->files)) {
			throw new Failure("There is no file at \"{$path}\".");
		}

		return $this->files[$path];
	}

	#[Override]
	public function write(string $path, string $contents): void {
		$this->files[$path] = $contents;
	}

	#[Override]
	public function exists(string $path): bool {
		return array_key_exists($path, $this->files);
	}

	/**
	 * **The payload is a value a test hands over, not a file to include.** What
	 * the real wrapper includes is `--coverage-php`'s output; here the test says
	 * what that would have returned.
	 */
	#[Override]
	public function payload(string $path): mixed {
		if(!array_key_exists($path, $this->payloads)) {
			throw new Failure("There is no payload at \"{$path}\".");
		}

		return $this->payloads[$path];
	}

	#[Override]
	public function remove(string $path): void {
		unset($this->files[$path]);
	}

	#[Override]
	public function isDirectory(string $path): bool {
		return in_array($path, $this->directories, TRUE);
	}

	/**
	 * **Prefix matching, because that is what a recursive walk amounts to.** A
	 * test that wants a tree writes the files and declares the root they are under;
	 * nothing has to declare the directories in between.
	 *
	 * **A directory that is not there throws**, the way the real one does: a walk
	 * that answered an empty list would make a missing-directory guard look like an
	 * operation with no result behind it.
	 *
	 * @param string $directory
	 * @return list<string>
	 */
	#[Override]
	public function entriesUnder(string $directory): array {
		if(!$this->isDirectory($directory)) {
			throw new Failure("There is no directory at \"{$directory}\".");
		}

		$prefix = rtrim($directory, "/") . "/";
		$entries = [];

		foreach(array_keys($this->files) as $path) {
			if(str_starts_with($path, $prefix)) {
				$entries[] = $path;
			}
		}

		sort($entries);

		return $entries;
	}

	#[Override]
	public function createDirectory(string $path): void {
		if($this->isDirectory($path)) {
			throw new Failure("The directory \"{$path}\" is already there.");
		}

		$this->directories[] = $path;
	}

	/**
	 * Every path that was locked, in order, so a test can assert the lock was
	 * taken rather than assume it.
	 *
	 * @var list<string>
	 */
	public array $locked = [];

	#[Override]
	public function rename(string $from, string $to): void {
		$this->files[$to] = $this->read($from);

		unset($this->files[$from]);
	}

	/**
	 * @template T
	 * @param string $path
	 * @param Closure(): T $critical
	 * @return T
	 */
	#[Override]
	public function underLock(string $path, Closure $critical): mixed {
		$this->locked[] = $path;

		return $critical();
	}

	/**
	 * Resolution an array cannot do for itself: the test says what a path
	 * resolves to, or it resolves to itself.
	 */
	#[Override]
	public function resolve(string $path): string {
		return $this->resolved[$path] ?? $path;
	}

	#[Override]
	public function digest(string $path): string {
		return $this->digests[$path] ?? hash("sha256", $this->read($path));
	}
}
