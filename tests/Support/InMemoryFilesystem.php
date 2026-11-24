<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Closure;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Failure;

/**
 * A filesystem in an array, failing where the real one fails.
 *
 * **This is what makes the unit suite deterministic.** There is no temporary
 * directory, no permission to arrange, nothing to clean up and nothing to skip
 * when the suite runs as root — a test states the bytes mule can see and
 * asserts the decisions it makes about them.
 *
 * **Entries come back in the order they were written**, deliberately. The real
 * filesystem's order is not something a test can choose, so any ordering mule
 * depends on has to be imposed by mule, where an assertion can catch it going
 * missing.
 */
final class InMemoryFilesystem implements FilesystemInterface {

	/** @var array<string, string> Path to contents. */
	private array $files = [];

	/** @var array<string, true> Path to nothing; a set of directories. */
	private array $directories = ["/" => TRUE];

	/** @var array<string, true> Paths that answer `exists()` and nothing else. */
	private array $phantoms = [];

	/** @var list<string> Every path anything has asked about, in order. */
	private array $probes = [];

	/** @var list<string> Every path a lock has been taken on, in order. */
	private array $locks = [];

	/** @var list<string> Every call that touched bytes while a lock was held, in order. */
	private array $callsUnderLock = [];

	private int $held = 0;

	/**
	 * Seeds a file, creating the directories above it.
	 *
	 * @param string $path
	 * @param string $contents
	 * @return self
	 */
	public function given(string $path, string $contents): self {
		$this->files[$path] = $contents;

		for($directory = dirname($path); $directory !== "/" && $directory !== "."; $directory = dirname($directory)) {
			$this->directories[$directory] = TRUE;
		}

		return $this;
	}

	/**
	 * Seeds an empty directory.
	 *
	 * @param string $path
	 * @return self
	 */
	public function givenDirectory(string $path): self {
		$this->directories[$path] = TRUE;

		return $this;
	}

	/**
	 * Seeds a path that is there when asked and gone when read.
	 *
	 * **This is the only way to state a race in a deterministic suite.** Code
	 * that checks for a file and then reads it makes two calls, and between
	 * them the file can be deleted by anything else on the machine; a test
	 * cannot arrange that with real bytes without becoming a race itself.
	 *
	 * @param string $path
	 * @return self
	 */
	public function givenVanishing(string $path): self {
		$this->phantoms[$path] = TRUE;

		return $this;
	}

	/**
	 * What a file holds now, for an assertion.
	 *
	 * @param string $path
	 * @return string|null
	 */
	public function contentsOf(string $path): ?string {
		return $this->files[$path] ?? NULL;
	}

	/**
	 * Every path this filesystem holds: directories first, then files, each in
	 * the order it was written. Not sorted, and not an order anything in `src/`
	 * may lean on.
	 *
	 * @return list<string>
	 */
	public function paths(): array {
		return [...array_keys($this->directories), ...array_keys($this->files)];
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public function exists(string $path): bool {
		$this->probes[] = $path;

		return isset($this->files[$path]) || isset($this->directories[$path]) || isset($this->phantoms[$path]);
	}

	/**
	 * Every path anything has asked about, in order — which is how a test
	 * states the walk a lookup makes rather than only the answer it reaches.
	 *
	 * @return list<string>
	 */
	public function probes(): array {
		return $this->probes;
	}

	/**
	 * @param string $path
	 * @return bool
	 */
	public function isDirectory(string $path): bool {
		return isset($this->directories[$path]);
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function read(string $path): string {
		$this->record("read {$path}");

		return $this->files[$path] ?? throw new Failure("Cannot read {$path}.");
	}

	/**
	 * @param string $path
	 * @param string $contents
	 * @return void
	 */
	public function write(string $path, string $contents): void {
		$this->record("write {$path}");

		if(!isset($this->directories[dirname($path)])) {
			throw new Failure("Cannot write {$path}.");
		}

		$this->files[$path] = $contents;
	}

	/**
	 * @param string $from
	 * @param string $to
	 * @return void
	 */
	public function rename(string $from, string $to): void {
		$this->record("rename {$from} to {$to}");

		$this->files[$to] = $this->files[$from] ?? throw new Failure("Cannot move {$from} to {$to}.");

		unset($this->files[$from]);
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function delete(string $path): void {
		if(!isset($this->files[$path])) {
			throw new Failure("Cannot remove the file {$path}.");
		}

		unset($this->files[$path]);
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function createDirectory(string $path): void {
		if($this->exists($path)) {
			throw new Failure("Cannot create the directory {$path}.");
		}

		$this->directories[$path] = TRUE;
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function createDirectoryExclusively(string $path): void {
		$this->createDirectory($path);
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function deleteDirectory(string $path): void {
		if(!isset($this->directories[$path]) || $this->entriesUnder($path) !== []) {
			throw new Failure("Cannot remove the directory {$path}.");
		}

		unset($this->directories[$path]);
	}

	/**
	 * @param string $directory
	 * @return list<string>
	 */
	public function entriesUnder(string $directory): array {
		$prefix = "{$directory}/";

		return array_values(array_filter(
			$this->paths(),
			static fn(string $path): bool => str_starts_with($path, $prefix),
		));
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function digest(string $path): string {
		return hash("sha256", $this->read($path));
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public function resolve(string $path): string {
		if(!$this->exists($path)) {
			throw new Failure("Cannot resolve {$path}: no such directory.");
		}

		return $path;
	}

	/**
	 * @return string
	 */
	public function temporaryDirectory(): string {
		return "/tmp";
	}

	/**
	 * Runs the critical section, recording that a lock was asked for and what
	 * was written while it was held. There is nothing to serialise — the unit
	 * suite is one process — so what a test can assert here is that the lock was
	 * taken on the right path and that the rewrite happened inside it. Whether
	 * the lock excludes anything is a question about the real filesystem, and is
	 * answered in `tests/Integration`.
	 *
	 * @template T
	 * @param string $path
	 * @param Closure(): T $critical
	 * @return T
	 */
	public function underLock(string $path, Closure $critical): mixed {
		$this->locks[] = $path;
		$this->held++;

		$result = $critical();

		$this->held--;

		return $result;
	}

	/**
	 * Every path a lock has been taken on, for an assertion.
	 *
	 * @return list<string>
	 */
	public function locks(): array {
		return $this->locks;
	}

	/**
	 * Every call that read or replaced bytes while a lock was held, in order,
	 * for an assertion. What a critical section *is* is the sequence inside it.
	 *
	 * @return list<string>
	 */
	public function callsUnderLock(): array {
		return $this->callsUnderLock;
	}

	/**
	 * @param string $call
	 * @return void
	 */
	private function record(string $call): void {
		if($this->held > 0) {
			$this->callsUnderLock[] = $call;
		}
	}
}
