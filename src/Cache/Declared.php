<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Project;

/**
 * The files that declare what a verdict means, and what they say right now.
 *
 * **A verdict is only ever a verdict under some configuration.** Adding a
 * mutator profile changes what "every mutant died" asserts, so the digests of
 * these files are recorded beside the verdicts and compared before any of them
 * is reused — see {@see Snapshot::staleAgainst()} for what a mismatch costs.
 *
 * **This is asked of the tree, not of the store**, which is the whole reason it
 * is a class rather than a list inside {@see Keying}. A store can only be
 * compared against its own recorded entries, so it cannot notice a declaration
 * that was not there when it was written: a project that gains an
 * `infection.json5` it never had would go on answering from a store that was
 * proven without one. Hashing the declared set from the tree makes a file
 * appearing, changing and vanishing the same kind of event.
 *
 * **`mule.json` is recorded, and it used to be the one file that was not.** It
 * held a coverage command, which differed between machines — a driver wrapper
 * on a laptop and nothing on CI — so hashing it would have made a committed
 * store unusable on the very machine it existed to help. Every key it has now
 * changes what a verdict asserts: what to mutate, what to leave out, which
 * mutator families are on. Nothing machine-local is left in it, because
 * enabling a coverage driver is done to the environment mule is run in rather
 * than written down.
 *
 * **The engine's own configuration file is deliberately absent**, and no longer
 * read by anything: mule renders what the engine needs per run from
 * `mule.json`. A project that still has an `infection.json5` lying around is
 * not consulted, so hashing it would unprove verdicts over a file that changes
 * nothing.
 */
final readonly class Declared {

	/**
	 * Every path that can declare the gate. They are committed and
	 * byte-identical on every machine, so recording them costs a portable
	 * store nothing.
	 *
	 * @var list<string>
	 */
	public const array PATHS = [
		"mule.json",
		"composer.json",
	];

	/**
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 */
	public function __construct(
		private Project $project,
		private FilesystemInterface $filesystem,
	) {}

	/**
	 * The digest of every declared file that is there, in path order.
	 *
	 * **Sorted, because the answer is compared with `===`.** A snapshot holds
	 * its sections sorted, so an unsorted answer here would differ from an
	 * identical one read back out of the store. The declared order is not
	 * sorted order either — mule's own configuration is looked for first.
	 *
	 * **An absent file is absent rather than empty.** A project has
	 * `infection.json5` or `infection.json` and not both, and a missing entry
	 * becoming a present one is exactly the change that has to be noticed.
	 *
	 * @return array<string, string>
	 */
	public function digests(): array {
		$digests = [];

		foreach(self::PATHS as $path) {
			if($this->filesystem->exists($this->project->absolute($path))) {
				$digests[$path] = $this->filesystem->digest($this->project->absolute($path));
			}
		}

		ksort($digests);

		return $digests;
	}
}
