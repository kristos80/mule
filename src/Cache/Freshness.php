<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Project;

/**
 * Whether the store still describes the tree, answered by hashing files.
 *
 * **This is the half of the cache that does not need a coverage report.**
 * Which files are stale was previously knowable only by generating one, which
 * made the cheap question depend on the expensive answer: a run had to spend
 * the whole coverage pass to discover it had nothing to do. The store records
 * what each test runs, so the same question is a few hundred digests.
 *
 * **It refuses rather than guesses.** A recorded file that has vanished, a
 * file nobody recorded that has appeared, a watched file whose bytes moved —
 * any of them and the answer is "ask a coverage run", because the store is
 * describing a tree that is no longer the one on disk. The only case it
 * answers for itself is the one it can be sure of.
 */
final readonly class Freshness {

	/**
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @param Universe $universe
	 */
	public function __construct(
		private Project $project,
		private FilesystemInterface $filesystem,
		private Universe $universe,
	) {}

	/**
	 * @param Snapshot $stored
	 * @return Standing
	 */
	public function of(Snapshot $stored): Standing {
		if(!$this->describes($stored)) {
			return Standing::unusable();
		}

		$gaps = $stored->gaps();

		return Standing::of(
			$this->asSeenNow($stored)->staleAgainst($stored),
			$gaps,
			$stored->count() + count($gaps),
		);
	}

	/**
	 * Whether the store is about this tree at all.
	 *
	 * @param Snapshot $stored
	 * @return bool
	 */
	private function describes(Snapshot $stored): bool {
		$digests = $stored->digests();

		return $digests !== []
			&& $this->allPresent($digests)
			&& $this->unchanged($stored->watched())
			&& $this->unchanged($stored->unprovenDigests())
			&& $this->nothingNew($digests);
	}

	/**
	 * @param array<string, string> $digests
	 * @return bool
	 */
	private function allPresent(array $digests): bool {
		foreach($digests as $path => $digest) {
			if(!$this->filesystem->exists($this->project->absolute($path))) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * Whether every one of these files still hashes to what was recorded.
	 *
	 * @param array<string, string> $digests
	 * @return bool
	 */
	private function unchanged(array $digests): bool {
		foreach($digests as $path => $digest) {
			if($this->digestOf($path) !== $digest) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * Whether the tree holds a PHP file the store has never heard of.
	 *
	 * @param array<string, string> $digests
	 * @return bool
	 */
	private function nothingNew(array $digests): bool {
		foreach($this->universe->paths() as $path) {
			if(!isset($digests[$path])) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * The store's own picture, re-hashed against the files as they are now.
	 *
	 * What each test runs is taken from the store rather than measured, which
	 * is sound precisely here: this is only ever asked where every file is
	 * present and no file is new, and what a test runs cannot change while
	 * nothing it could run has.
	 *
	 * @param Snapshot $stored
	 * @return Snapshot
	 */
	private function asSeenNow(Snapshot $stored): Snapshot {
		$verified = [];
		$tests = [];

		foreach($stored->paths() as $path) {
			$verified[$path] = $this->digestOf($path);
		}

		foreach($stored->tests() as $path => $test) {
			$tests[$path] = new Touched($this->digestOf($path), $test->touches);
		}

		return Snapshot::of($verified, $tests);
	}

	/**
	 * @param string $path
	 * @return string
	 */
	private function digestOf(string $path): string {
		return $this->filesystem->digest($this->project->absolute($path));
	}
}
