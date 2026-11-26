<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Cache\Toolchain;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Project;
use function str_ends_with;

/**
 * Every file whose bytes can change a verdict, and what it hashes to now.
 *
 * **The tree is enumerated, never the store (G9).** A loop driven by what the
 * store already knows can only rediscover what it already knows, which is how a
 * file that was added and never covered quietly never gets gated — the failure
 * that looks exactly like success. Listing the directories is what makes a new
 * file visible as new.
 *
 * **Excluded paths are dropped here, once.** They are not digested, not keyed and
 * not counted as having appeared — which matters, because a file in the digest map
 * that no part of the store mentions reads as "the store describes a different
 * tree" and would make every run a cold one (`Cache\Standing`).
 */
final readonly class Universe {

	/**
	 * @param FilesystemInterface $filesystem
	 * @param Project $project
	 * @param Configuration $configuration
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private Project $project,
		private Configuration $configuration,
	) {}

	/**
	 * The source and test files, which are the ones the store speaks about.
	 *
	 * **Support files are deliberately not in here.** A path in this map that no
	 * part of the store mentions reads as "the store describes a different tree"
	 * and makes every run a cold one (`Cache\Standing`) — and the store cannot
	 * mention a support file, because nothing instrumented ever names one. They go
	 * into the toolchain digest instead, where invalidating everything at once is
	 * what the section already means (`Cache\Toolchain::of()`).
	 *
	 * @return array<string, non-empty-string> Project-relative path to digest.
	 */
	public function digests(): array {
		$digests = [];

		foreach([$this->configuration->source, $this->configuration->tests] as $root) {
			foreach($this->under($root) as $path) {
				if(!$this->configuration->excludes($path)) {
					$digests[$path] = $this->filesystem->digest($this->project->absolute($path));
				}
			}
		}

		// **Sorted, because the first name in it reaches a message.** A file the
		// store has never heard of makes the run whole, and which one gets named is
		// otherwise whichever root happened to be walked first.
		ksort($digests);

		return $digests;
	}

	/**
	 * One digest over every support file: the paths and their contents together,
	 * so adding an empty file moves it and so does renaming one.
	 *
	 * @return non-empty-string
	 */
	public function support(): string {
		$digests = [];

		foreach($this->configuration->support as $root) {
			foreach($this->under($root) as $path) {
				$digests[$path] = $this->filesystem->digest($this->project->absolute($path));
			}
		}

		// **Not sorted here.** The paths arrive from `entriesUnder()`, which sorts a
		// tree walk because mule's decisions must not depend on the filesystem's
		// order — so a `ksort` on top of it was an operation with no result behind
		// it, and the gate said so.
		return Toolchain::digest($digests);
	}

	/**
	 * The files mule is answerable for: everything under the source root that is
	 * not excluded.
	 *
	 * **This is the list a cold run keys**, which is how a source file no test ever
	 * reaches gets a verdict of "unproven" rather than no verdict at all.
	 *
	 * @return list<string>
	 */
	public function sources(): array {
		return $this->partitioned()[0];
	}

	/**
	 * The source files somebody decided mule is not answerable for.
	 *
	 * **Counted and reported rather than merely skipped.** A file that is not
	 * gated is a file whose mutants nobody will ever see, and the difference
	 * between a project with two of those and one with two hundred is not visible
	 * in a passing run otherwise.
	 *
	 * @return list<string>
	 */
	public function excluded(): array {
		return $this->partitioned()[1];
	}

	/**
	 * @return array{0: list<string>, 1: list<string>} The source files mule gates, and the ones it does not.
	 */
	private function partitioned(): array {
		$gated = [];
		$excluded = [];

		foreach($this->under($this->configuration->source) as $path) {
			if($this->configuration->excludes($path)) {
				$excluded[] = $path;

				continue;
			}

			$gated[] = $path;
		}

		return [$gated, $excluded];
	}

	/**
	 * **A missing root is not an error here.** `support` is a list a consumer
	 * writes, and a directory that is not there yet contributes no bytes — which is
	 * the same answer as an empty one, and a less confusing one than a crash from
	 * inside a digest loop.
	 *
	 * @param string $root Project-relative.
	 * @return list<string>
	 */
	private function under(string $root): array {
		$absolute = $this->project->absolute($root);

		if(!$this->filesystem->isDirectory($absolute)) {
			return [];
		}

		$paths = [];

		foreach($this->filesystem->entriesUnder($absolute) as $entry) {
			$path = $this->project->relative($entry);

			if(str_ends_with($path, ".php")) {
				$paths[] = $path;
			}
		}

		return $paths;
	}
}
