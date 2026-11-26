<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * The disk, behind a seam.
 *
 * **Everything mule knows about the filesystem comes through here**, so the rest
 * of the program is testable without one and makes the same decisions given the
 * same answers. The wrapper on the other side is straight-line: every statement
 * is a call, an assignment, a return or a throw, and anything that *decides*
 * lives on this side of the interface.
 *
 * A failure nothing can be done about — a file that cannot be read, a digest
 * that cannot be taken — throws from the wrapper, so no caller carries a branch
 * for it and no test has to arrange a hostile filesystem to reach one.
 */
interface FilesystemInterface {

	/**
	 * @param string $path
	 * @return string
	 */
	public function read(string $path): string;

	/**
	 * Writes a file. **Does not create the directories above it**, because
	 * whether a missing directory is made or complained about is a decision, and
	 * decisions live on this side of the seam.
	 *
	 * @param string $path
	 * @param string $contents
	 * @return void
	 */
	public function write(string $path, string $contents): void;

	/**
	 * @param string $path
	 * @return bool
	 */
	public function exists(string $path): bool;

	/**
	 * @param string $path
	 * @return bool
	 */
	public function isDirectory(string $path): bool;

	/**
	 * Creates a directory and whatever is missing above it.
	 *
	 * Fails if it is already there: `mkdir` answers `FALSE` for that, and
	 * deciding whether that is a problem belongs to the caller.
	 *
	 * @param string $path
	 * @return void
	 */
	public function createDirectory(string $path): void;

	/**
	 * A path with `.`, `..` and symlinks resolved.
	 *
	 * Needed because Composer answers `findFile()` with paths like
	 * `…/vendor/composer/../../tests/X.php`, and a key cannot be taken over two
	 * spellings of one file.
	 *
	 * @param string $path
	 * @return string
	 */
	public function resolve(string $path): string;

	/**
	 * The digest of a file's bytes.
	 *
	 * **This is the only thing mule trusts about a file.** A coverage report
	 * carries a `hash` attribute of its own, and it is recomputed from disk when
	 * the report is rendered — so it matches the current file even when the data
	 * beside it is stale, and it cannot be used as a staleness guard. See
	 * `docs/keying.md` M8.
	 *
	 * @param string $path
	 * @return non-empty-string
	 */
	public function digest(string $path): string;
}
