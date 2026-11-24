<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\External\RandomInterface;
use Kristos80\Mule\Project;

/**
 * The committed record of which files are known to be at the full score.
 *
 * **It belongs in the repository.** A content-hash verdict is not machine-local
 * state: the same bytes give the same answer on the next machine, which
 * inherits the work rather than re-proving it. That is the property an engine's
 * own cache cannot have — Pest's sits under `vendor/` and keys on absolute
 * paths, so a fresh CI checkout starts from nothing.
 *
 * **Nothing is recorded that a passing run did not prove.** `bank()` is only
 * ever reached after the engine exits clean under a full-score gate, so every
 * file in that run's scope had every mutant killed. There is no log parsing and
 * no per-file bookkeeping: the cost is that a failing run banks nothing, even
 * the files that were clean, and the next one re-mutates them.
 *
 * **The file is replaced, never edited.** The merged store is written beside the
 * old one and moved over it, so a reader sees one or the other and a crash
 * leaves a scratch file rather than half a store.
 *
 * **And the read, the merge and the replace happen under one lock**, because
 * replacing a file atomically is not the same as merging into it safely: two
 * runs that both read the store before either wrote it would each merge onto
 * the bytes they read, and the second rename would silently drop the first
 * one's entries. Running the gate in two sessions at once is a habit worth
 * supporting — one for the class being worked on, one proving the repository —
 * and the cost of losing a verdict is a file re-mutated for no reason.
 *
 * **The lock is a file of its own, in the system temporary directory** — see
 * {@see self::lockFor()} for why it is neither the store nor anything beside
 * it. Nothing in a repository has to be ignored, which is what makes this
 * invisible to the projects that commit the store.
 */
final readonly class Store {

	public const string PATH = ".mule/verified.json";

	/**
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @param RandomInterface $random
	 */
	public function __construct(
		private Project $project,
		private FilesystemInterface $filesystem,
		private RandomInterface $random,
	) {}

	/**
	 * What the store claims, or nothing where it has not been written yet.
	 *
	 * @return Snapshot
	 */
	public function read(): Snapshot {
		$file = $this->project->absolute(self::PATH);

		return $this->filesystem->exists($file)
			? Snapshot::fromJson($this->filesystem->read($file))
			: Snapshot::of([], []);
	}

	/**
	 * Records the given files' verdicts over what is already there.
	 *
	 * @param Snapshot $current The run's own snapshot, which the banked paths come from.
	 * @param list<string> $paths
	 * @return int How many files were recorded.
	 */
	public function bank(Snapshot $current, array $paths): int {
		$banked = $current->only($paths);
		$file = $this->project->absolute(self::PATH);

		return $this->filesystem->underLock($this->lockFor($file), fn(): int => $this->merge($banked, $file));
	}

	/**
	 * The critical section: the directory if it is not there yet, then what is
	 * on disk plus what this run proved, written beside the store and moved over
	 * it.
	 *
	 * **Creating the directory is inside the lock because it is itself a race.**
	 * Two runs on a fresh checkout both find no `.mule`, both create it, and the
	 * loser is handed an error about a directory that now exists — which is how
	 * an integration test with eight workers failed before the lock covered
	 * this too.
	 *
	 * @param Snapshot $banked
	 * @param string $file
	 * @return int
	 */
	private function merge(Snapshot $banked, string $file): int {
		$directory = dirname($file);

		if(!$this->filesystem->exists($directory)) {
			$this->filesystem->createDirectory($directory);
		}

		$scratch = "{$file}.{$this->random->token()}";

		$this->filesystem->write($scratch, $this->read()->merged($banked)->toJson());
		$this->filesystem->rename($scratch, $file);

		return $banked->count();
	}

	/**
	 * Where the lock for a store lives, which is **not** in the store's own
	 * directory.
	 *
	 * Two reasons, and either alone would settle it. The directory may not exist
	 * yet, and creating it has to happen *under* the lock, so the lock cannot
	 * need it. And `flock()` holds an inode rather than a name, so a lock on
	 * anything this method replaces by rename would leave the next process
	 * locking the new inode and walking into a section the first is still in.
	 *
	 * The system temporary directory always exists, and keying the name on the
	 * store's absolute path keeps two checkouts on one machine from blocking
	 * each other while keeping two runs of the same one honest. Nothing has to
	 * clean it up: an empty lock file costs nothing and removing it would
	 * reintroduce exactly the inode race it exists to avoid.
	 *
	 * @param string $file
	 * @return string
	 */
	private function lockFor(string $file): string {
		return $this->filesystem->temporaryDirectory() . "/mule-" . hash("sha256", $file) . ".lock";
	}
}
