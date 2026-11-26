<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\External\ClassesInterface;

/**
 * What a verdict would mean if it were proven now: the gate as mule reads it,
 * and the engine that would enforce it.
 *
 * **A verdict is only ever a verdict under some configuration**, so these are
 * recorded beside the verdicts and compared before any of them is reused — see
 * {@see Snapshot::staleAgainst()} for what a mismatch costs.
 *
 * **It is the decision that is hashed, not the bytes it came from.** Digesting
 * whole files was simpler and wrong in both directions. `composer.json` moved
 * whenever a dependency did, so `composer require` threw away every verdict in
 * the store — measured on a real project, where removing two unused dev
 * dependencies cost 610 of them. And `mule.json` moved whenever `threads` or
 * `min-coverage` did, neither of which changes what a mutant proves, so tuning
 * a run's speed re-proved the project. Hashing what mule decided rather than
 * what it read means reformatting either file, reordering its keys, or writing
 * a default out explicitly all change nothing.
 *
 * **`composer.json` is not consulted at all now.** The only thing in it that
 * changes a verdict is where the source lives, and that arrives here already
 * parsed as the gate's `source`. A test root appearing or vanishing is caught
 * by the universe instead: {@see Freshness} refuses a store that does not name
 * every file the project autoloads, and `vendor/` is not one of them.
 *
 * **These are labels rather than paths**, which is why they are kept out of
 * {@see Snapshot::digests()}. Everything there is a file that has to exist, is
 * re-hashed on every read and pruned when it goes; a label among them would be
 * read as a missing file and dropped on every write.
 */
final readonly class Declared {

	/** What the whole gate is recorded under. */
	private const string GATE = "gate";

	/** The engine whose version decides what a passing gate asserts. */
	private const string ENGINE = "infection/infection";

	/**
	 * @param Configuration $configuration
	 * @param ClassesInterface $classes
	 */
	public function __construct(
		private Configuration $configuration,
		private ClassesInterface $classes,
	) {}

	/**
	 * The gate as one digest.
	 *
	 * **Only the keys that decide what a mutant proves are in it.** `threads`
	 * is how fast the engine goes, `min-coverage` is an assertion about lines
	 * rather than about mutants, and `narrow` is how coverage is measured
	 * rather than what was proven. None of the three can change a verdict, so
	 * none of them unproves one.
	 *
	 * **Listed rather than named.** A digest never reads its own keys, so the
	 * names were decoration — and `"tests" => $value` is one mutation away from
	 * `"tests" > $value`, which keeps enough sensitivity to survive any test
	 * that can be written for a boolean. Positions carry the same information
	 * with nothing to rewrite: drop one and the gate stops noticing that
	 * setting, which every case below catches.
	 *
	 * @return array<string, string>
	 */
	public function digests(): array {
		return [self::GATE => hash("sha256", json_encode([
			$this->configuration->source,
			$this->configuration->exclude,
			$this->configuration->tests,
			$this->configuration->mutators,
			$this->configuration->mutateUncovered,
			$this->configuration->timeout,
		], JSON_THROW_ON_ERROR))];
	}

	/**
	 * The engine that would prove a verdict now, as a package and its version.
	 *
	 * **Recorded because which mutants exist is the engine's business**, so two
	 * versions are two gates. Measured the hard way: a patch-range bump moved
	 * the engine under 610 recorded verdicts on a real project and surfaced as
	 * a hundred and seventy-three unexplained survivors, with every source
	 * file, every test and every configuration byte identical.
	 *
	 * **An engine with no version recorded answers nothing.** A package
	 * installed from a branch has no pretty version, and refusing to run would
	 * be worse than recording that the question could not be answered.
	 *
	 * @return array<string, string>
	 */
	public function engine(): array {
		$version = $this->classes->versionOf(self::ENGINE);

		return $version === NULL ? [] : [self::ENGINE => $version];
	}
}
