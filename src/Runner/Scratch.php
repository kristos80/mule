<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\External\RandomInterface;

/**
 * Scratch space, taken and given back.
 *
 * **Every run gets its own directory.** A fixed path under the temporary
 * directory is shared by every session on the machine, so running the gate in
 * two terminals at once — normal when one is narrowed and the other is proving
 * the whole project — means one run's cleanup landing in the middle of the
 * other's coverage.
 */
final readonly class Scratch {

	/**
	 * @param FilesystemInterface $filesystem
	 * @param RandomInterface $random
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private RandomInterface $random,
	) {}

	/**
	 * **Claimed in one step, under a name nothing can guess.** Creating the
	 * directory fails rather than joining one that is already there, so there
	 * is no window between checking a name and taking it.
	 *
	 * @return Run
	 */
	public function create(): Run {
		$run = new Run("{$this->filesystem->temporaryDirectory()}/mule.{$this->random->token()}");

		$this->filesystem->createDirectoryExclusively($run->directory);

		return $run;
	}

	/**
	 * Writes the configuration mule hands the engine into the run's own
	 * directory.
	 *
	 * **Here rather than in the gate**, because the gate holds no filesystem:
	 * what it does is decide, and the scratch directory's contents belong to
	 * whatever owns the scratch directory. It is removed with everything else
	 * the run leaves behind.
	 *
	 * @param Run $run
	 * @param string $declaration
	 * @return void
	 */
	public function declare(Run $run, string $declaration): void {
		$this->filesystem->write($run->engineJson(), $declaration);
	}

	/**
	 * The log the engine left, or nothing where it left none.
	 *
	 * **Absent is not an error.** An engine that died before writing one, or
	 * that was never asked, leaves mule with no per-file answer — and no
	 * per-file answer means nothing is banked, which is exactly the behaviour
	 * being replaced rather than a new failure.
	 *
	 * @param Run $run
	 * @return string
	 */
	public function mutants(Run $run): string {
		return $this->filesystem->exists($run->mutantsJson())
			? $this->filesystem->read($run->mutantsJson())
			: "";
	}

	/**
	 * Removes the run's directory and everything a coverage run left in it.
	 *
	 * **Longest path first is deepest first**, because a directory's path is a
	 * strict prefix of everything inside it and therefore shorter than all of
	 * it. That is what lets this delete a tree with no recursion and no
	 * reliance on the order the filesystem listed it in.
	 *
	 * A directory that is not there is not an error: this runs while an earlier
	 * failure is on its way out, and a second failure here would bury the first.
	 *
	 * @param Run $run
	 * @return void
	 */
	public function remove(Run $run): void {
		if(!$this->filesystem->isDirectory($run->directory)) {
			return;
		}

		$entries = $this->filesystem->entriesUnder($run->directory);

		usort($entries, static fn(string $one, string $other): int => strlen($other) <=> strlen($one));

		foreach($entries as $entry) {
			$this->filesystem->isDirectory($entry)
				? $this->filesystem->deleteDirectory($entry)
				: $this->filesystem->delete($entry);
		}

		$this->filesystem->deleteDirectory($run->directory);
	}
}
