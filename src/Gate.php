<?php
declare(strict_types=1);

namespace Kristos80\Mule;

use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Reading;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Console\Summary;
use Kristos80\Mule\Engine\Infection;
use Kristos80\Mule\External\OutputInterface;
use Kristos80\Mule\External\ProcessesInterface;
use Kristos80\Mule\Runner\Run;
use Kristos80\Mule\Runner\Scratch;

/**
 * The gate itself: coverage once, then the engine over whatever still needs
 * proving, then the verdict banked.
 *
 * **Coverage is generated every time.** An engine refuses to run without it,
 * and it is the cheap half, so there is no mode that skips it — which is also
 * what lets the verdicts be read from a fresh covering-test set before anything
 * decides what is stale, rather than storing one and trusting it.
 *
 * **The verdicts are read once, before the engine starts.** Banking happens
 * after the engine finishes, and hashing the tree then would record whatever
 * the files say at that moment — so editing a class while the gate ran would
 * bank a verdict for content nothing had mutated. Reading them from the same
 * coverage report the engine was handed cannot drift from what was proven, and
 * an edit made meanwhile is simply stale on the next run, which is correct.
 *
 * **Only a clean exit banks anything.** The engine is gated at the full score,
 * so exit 0 proves every mutant in its scope died.
 */
final readonly class Gate {

	/**
	 * @param Project $project
	 * @param Configuration $configuration
	 * @param Keying $keying
	 * @param Store $store
	 * @param Infection $engine
	 * @param Scratch $scratch
	 * @param ProcessesInterface $processes
	 * @param OutputInterface $output
	 */
	public function __construct(
		private Project $project,
		private Configuration $configuration,
		private Keying $keying,
		private Store $store,
		private Infection $engine,
		private Scratch $scratch,
		private ProcessesInterface $processes,
		private OutputInterface $output,
	) {}

	/**
	 * Mutates the whole project, and records every file it proves.
	 *
	 * @return int
	 */
	public function full(): int {
		return $this->gated(FALSE);
	}

	/**
	 * Mutates only the files whose verdict the store cannot vouch for.
	 *
	 * @return int
	 */
	public function narrowed(): int {
		return $this->gated(TRUE);
	}

	/**
	 * Reports what the store claims, mutating nothing.
	 *
	 * @return int
	 */
	public function status(): int {
		$run = $this->scratch->create();

		try {
			$coverage = $this->cover($run);

			if($coverage !== 0) {
				return $coverage;
			}

			$this->report($this->keying->of($run->coverageXml()));

			return 0;
		} finally {
			$this->scratch->remove($run);
		}
	}

	/**
	 * Coverage, then the engine over the scope, then the bank.
	 *
	 * @param bool $narrowed Whether to mutate only what the store cannot vouch for.
	 * @return int
	 */
	private function gated(bool $narrowed): int {
		$run = $this->scratch->create();

		try {
			$coverage = $this->cover($run);

			if($coverage !== 0) {
				return $coverage;
			}

			$reading = $this->keying->of($run->coverageXml());
			$unfinished = $this->report($reading);
			$scope = $narrowed ? $unfinished : NULL;

			if($scope === []) {
				$this->output->line(Summary::nothingToMutate());

				return 0;
			}

			$status = $this->processes->run($this->engine->command($run, $scope), $this->project->root);

			if($status !== 0) {
				return $status;
			}

			$banked = $this->store->bank($reading->snapshot, $this->bankable($reading, $scope));

			$this->output->line(Summary::banked($banked, Store::PATH));

			return 0;
		} finally {
			$this->scratch->remove($run);
		}
	}

	/**
	 * Generates the coverage report this run works from.
	 *
	 * @param Run $run
	 * @return int
	 */
	private function cover(Run $run): int {
		$command = array_map(static fn(string $argument): string => str_replace(
			[Configuration::COVERAGE_PLACEHOLDER, Configuration::JUNIT_PLACEHOLDER],
			[$run->coverageXml(), $run->junit()],
			$argument,
		), $this->configuration->coverage);

		return $this->processes->run($command, $this->project->root);
	}

	/**
	 * Says how much of the project the store vouches for, and answers the rest.
	 *
	 * @param Reading $reading
	 * @return list<string> Everything that still needs the engine: stale, plus never bankable.
	 */
	private function report(Reading $reading): array {
		$stale = $reading->snapshot->staleAgainst($this->store->read());
		$unproven = $reading->unproven();
		$unfinished = [...$stale, ...$unproven];

		$this->output->line(Summary::verified($reading->count(), count($stale), count($unproven)));

		foreach(Summary::unreached($reading->gaps) as $line) {
			$this->output->line($line);
		}

		sort($unfinished);

		return $unfinished;
	}

	/**
	 * Of the files this run mutated, the ones it may record.
	 *
	 * A file the tests do not reach all of is mutated like any other and
	 * recorded like none: the engine only ever made mutants for the lines that
	 * ran, so a clean exit says nothing about the rest of it.
	 *
	 * @param Reading $reading
	 * @param list<string>|null $scope What was mutated, or NULL for the whole project.
	 * @return list<string>
	 */
	private function bankable(Reading $reading, ?array $scope): array {
		return $scope === NULL ? $reading->snapshot->paths() : $reading->snapshot->knownOf($scope);
	}
}
