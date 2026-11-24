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
 * **`mule.json` is deliberately absent.** It is the one file whose purpose is
 * to differ between machines — a coverage driver wrapper on a laptop, nothing
 * on CI, which is why CI deletes it — so recording it would make a committed
 * store unusable on the very machine it exists to help. The cost, which is the
 * right way round: changing the coverage command or the engine's options
 * invalidates nothing, so run `mule full` after changing one.
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
		"composer.json",
		"infection.json5",
		"infection.json",
		"infection.json5.dist",
		"infection.json.dist",
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
	 * sorted order either — `infection.json5` is looked for before
	 * `infection.json`.
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
