<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\External\FilesystemInterface;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * The store, on disk.
 *
 * **Committed on purpose.** A content-hashed verdict is not machine-local state,
 * so the next machine and CI inherit the work instead of re-proving it. That is
 * the whole point, and it is why every path in it is project-relative and why its
 * bytes are canonical (`Snapshot`).
 */
final readonly class Store {

	/**
	 * @param FilesystemInterface $filesystem
	 * @param string $path The store itself, absolute.
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private string $path,
	) {}

	/**
	 * What the store says, or an empty snapshot under the toolchain given.
	 *
	 * **A missing store is not a failure.** The first run has nothing to inherit,
	 * and an empty snapshot is exactly what it should merge into. A store that is
	 * *present* and unreadable is a different matter and `StoreFormat` refuses it.
	 *
	 * The stored toolchain is returned as it was found rather than quietly
	 * replaced: whether it still describes this environment is a decision, and
	 * the run that notices should be able to say so.
	 *
	 * @param non-empty-string $toolchain
	 * @return Snapshot
	 */
	public function read(string $toolchain): Snapshot {
		if(!$this->filesystem->exists($this->path)) {
			return new Snapshot([], [], $toolchain);
		}

		// Named arguments, so the depth is not passed at all. Spelling out its
		// default made `512` an argument nothing could observe, and the gate
		// found it by changing it to 511 and to 513 with no effect either way.
		return StoreFormat::read(json_decode(
			$this->filesystem->read($this->path),
			associative: TRUE,
			flags: JSON_THROW_ON_ERROR,
		));
	}

	/**
	 * Merges a run's findings into the store and writes it.
	 *
	 * **Re-read under the lock, not before it.** Two runs finishing in the same
	 * instant otherwise means the later one's file wins and the earlier one's
	 * entries are lost — which cost a run's work each time it happened, because a
	 * missing entry is simply re-proven next time, but it is still work thrown
	 * away for nothing.
	 *
	 * **Written beside the old one and moved over.** A reader sees one complete
	 * store or the other, and a crash between the two leaves a scratch file rather
	 * than half a store. The scratch name is fixed rather than random because only
	 * one writer holds the lock, and a leftover from a crash is overwritten by the
	 * next run.
	 *
	 * @param Snapshot $proven What this run established.
	 * @return Snapshot The store as it now stands.
	 */
	public function bank(Snapshot $proven): Snapshot {
		$directory = dirname($this->path);

		if(!$this->filesystem->isDirectory($directory)) {
			$this->filesystem->createDirectory($directory);
		}

		return $this->filesystem->underLock("{$this->path}.lock", function () use ($proven): Snapshot {
			$merged = $this->read($proven->toolchain)->with($proven);
			$scratch = "{$this->path}.writing";

			$this->filesystem->write($scratch, StoreFormat::write($merged));
			$this->filesystem->rename($scratch, $this->path);

			return $merged;
		});
	}
}
