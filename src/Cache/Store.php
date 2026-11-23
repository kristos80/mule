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
 * leaves a scratch file rather than half a store. Two runs finishing in the
 * same instant means the later one's file wins and the earlier one's new
 * entries are lost — which costs that run's work and nothing else, since a
 * missing entry is simply re-proven next time.
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
	 * @return Verdicts
	 */
	public function read(): Verdicts {
		$file = $this->project->absolute(self::PATH);

		return $this->filesystem->exists($file)
			? Verdicts::fromJson($this->filesystem->read($file))
			: Verdicts::of([]);
	}

	/**
	 * Records the given files' verdicts over what is already there.
	 *
	 * @param Verdicts $current The run's own verdicts, which the banked paths come from.
	 * @param list<string> $paths
	 * @return int How many files were recorded.
	 */
	public function bank(Verdicts $current, array $paths): int {
		$banked = $current->only($paths);
		$file = $this->project->absolute(self::PATH);
		$directory = dirname($file);

		if(!$this->filesystem->exists($directory)) {
			$this->filesystem->createDirectory($directory);
		}

		$scratch = "{$file}.{$this->random->token()}";

		$this->filesystem->write($scratch, $this->read()->merged($banked)->toJson());
		$this->filesystem->rename($scratch, $file);

		return $banked->count();
	}
}
