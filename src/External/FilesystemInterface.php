<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Closure;

/**
 * Every filesystem call mule makes, one method per primitive.
 *
 * **This is the seam determinism rests on.** Nothing outside `src/External`
 * touches a disk, so the whole program can be driven over an array in memory:
 * the same bytes in produce the same decisions, the same command line and the
 * same store out, with no temporary directory, no permissions and nothing to
 * clean up afterwards.
 *
 * **Where a primitive can fail, two shapes are possible and the choice is
 * deliberate.** A failure nothing can do anything about — a file that cannot be
 * read, a digest that cannot be taken — throws from the wrapper, so no caller
 * carries a branch for it and no test has to arrange a hostile filesystem to
 * reach one. A failure the caller must *decide* about is returned as a value
 * (see {@see ClassesInterface::fileOf()}), because the decision belongs in a
 * tested class rather than in a wrapper.
 */
interface FilesystemInterface {

	/**
	 * Whether anything exists at a path.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function exists(string $path): bool;

	/**
	 * Whether a path is a directory.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isDirectory(string $path): bool;

	/**
	 * A file's bytes.
	 *
	 * @param string $path
	 * @return string
	 */
	public function read(string $path): string;

	/**
	 * Writes a file, replacing what was there.
	 *
	 * @param string $path
	 * @param string $contents
	 * @return void
	 */
	public function write(string $path, string $contents): void;

	/**
	 * Moves a file over whatever is at the destination, atomically within one
	 * filesystem. This is how the store is replaced: a reader sees the old file
	 * or the new one, never a half-written one.
	 *
	 * @param string $from
	 * @param string $to
	 * @return void
	 */
	public function rename(string $from, string $to): void;

	/**
	 * Removes a file.
	 *
	 * @param string $path
	 * @return void
	 */
	public function delete(string $path): void;

	/**
	 * Creates a directory and any parent it needs. Fails on a directory that
	 * already exists: whether that is worth tolerating is the caller's
	 * decision, and it is made where a test can reach it.
	 *
	 * @param string $path
	 * @return void
	 */
	public function createDirectory(string $path): void;

	/**
	 * Creates one directory, readable only by this user, failing if the name is
	 * already taken. This is how a run claims its scratch space: there is no
	 * window between checking a name and taking it.
	 *
	 * @param string $path
	 * @return void
	 */
	public function createDirectoryExclusively(string $path): void;

	/**
	 * Removes an empty directory.
	 *
	 * @param string $path
	 * @return void
	 */
	public function deleteDirectory(string $path): void;

	/**
	 * Every file and directory under a directory, at any depth, in whatever
	 * order the filesystem gives them. **Callers that need an order impose it**,
	 * because an order that comes from the filesystem is one no test can choose
	 * and no mutation can be caught changing.
	 *
	 * @param string $directory
	 * @return list<string>
	 */
	public function entriesUnder(string $directory): array;

	/**
	 * A file's SHA-256 digest.
	 *
	 * @param string $path
	 * @return string
	 */
	public function digest(string $path): string;

	/**
	 * An existing path with its symlinks resolved and no trailing separator.
	 *
	 * @param string $path
	 * @return string
	 */
	public function resolve(string $path): string;

	/**
	 * Where this machine keeps temporary files.
	 *
	 * @return string
	 */
	public function temporaryDirectory(): string;

	/**
	 * Runs something with an exclusive lock held on a path, creating the file if
	 * it is not there and waiting for whoever holds it.
	 *
	 * **The closure is what makes this one method rather than three.** A lock
	 * taken and released by separate calls would let a caller forget the second,
	 * and nothing in a wrapper may decide — so the critical section is handed in
	 * and the deciding stays in the tested class it came from.
	 *
	 * @template T
	 * @param string $path The file to lock, which is never the file being rewritten.
	 * @param Closure(): T $critical
	 * @return T
	 */
	public function underLock(string $path, Closure $critical): mixed;
}
