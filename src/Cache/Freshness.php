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
		$recorded = $stored->digests();
		$now = $this->digestsOf($recorded);

		if($now === NULL || !$this->describes($stored, $recorded, $now)) {
			return Standing::unusable();
		}

		$gaps = $stored->gaps();
		$stale = $this->asSeenNow($stored, $now)->staleAgainst($stored);
		$outstanding = array_flip([...$stale, ...array_keys($gaps)]);

		return Standing::of(
			$stale,
			$gaps,
			$stored->count() + count($gaps),
			$this->testsRunning($stored, $outstanding),
		);
	}

	/**
	 * Every recorded file as it hashes now, or NULL where one has gone.
	 *
	 * **Hashed once, here.** Everything below is derived from this map rather
	 * than re-reading the tree, which is the difference between one pass over
	 * a thousand files and three.
	 *
	 * @param array<string, string> $recorded
	 * @return array<string, string>|null
	 */
	private function digestsOf(array $recorded): ?array {
		$now = [];

		foreach($recorded as $path => $digest) {
			if(!$this->filesystem->exists($this->project->absolute($path))) {
				return NULL;
			}

			$now[$path] = $this->digestOf($path);
		}

		return $now === [] ? NULL : $now;
	}

	/**
	 * Whether the store is about this tree at all.
	 *
	 * @param Snapshot $stored
	 * @param array<string, string> $recorded
	 * @param array<string, string> $now
	 * @return bool
	 */
	private function describes(Snapshot $stored, array $recorded, array $now): bool {
		return $this->same($stored->watched(), $now)
			&& $this->same($stored->unprovenDigests(), $now)
			&& $this->nothingNew($recorded);
	}

	/**
	 * @param array<string, string> $digests
	 * @param array<string, string> $now
	 * @return bool
	 */
	private function same(array $digests, array $now): bool {
		foreach($digests as $path => $digest) {
			if($now[$path] !== $digest) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * The tests a run would have to execute again: every test that runs
	 * anything still outstanding.
	 *
	 * **Every one of them, not just the ones that changed.** A file is being
	 * re-keyed, and its key is what the report says covers it — so a run that
	 * executed only some of its tests would come back saying the rest of it is
	 * unreached, and record a file as half-covered that is nothing of the kind.
	 *
	 * **The set is closed.** A test that runs nothing outstanding cannot have
	 * a different footprint either: for it to have changed, something it runs
	 * would have had to change, which would have made that file outstanding.
	 *
	 * They come out in path order because a snapshot holds them in path order;
	 * there is nothing here to sort.
	 *
	 * @param Snapshot $stored
	 * @param array<string, int> $outstanding
	 * @return list<string>
	 */
	private function testsRunning(Snapshot $stored, array $outstanding): array {
		$tests = [];

		foreach($stored->tests() as $path => $test) {
			if($test->runsAnyOf($outstanding)) {
				$tests[] = $path;
			}
		}

		return $tests;
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
	 * @param array<string, string> $now
	 * @return Snapshot
	 */
	private function asSeenNow(Snapshot $stored, array $now): Snapshot {
		$verified = [];
		$tests = [];

		foreach($stored->paths() as $path) {
			$verified[$path] = $now[$path];
		}

		foreach($stored->tests() as $path => $test) {
			$tests[$path] = new Touched($now[$path], $test->touches);
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
