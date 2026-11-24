<?php
declare(strict_types=1);

namespace Kristos80\Mule;

use Kristos80\Mule\Cache\Freshness;
use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Reading;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Standing;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Console\Summary;
use Kristos80\Mule\Coverage\Unreached;
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
 * **This run's own verdicts are read once, from the report the engine was
 * handed.** Banking happens after the engine finishes, and keying the tree
 * then would record whatever the files say at that moment — so editing a class
 * while the gate ran would bank a verdict for content nothing had mutated.
 * Reading them from the same report cannot drift from what was proven.
 *
 * **The store, by contrast, is read again when the scope is worked out**, and
 * that is deliberate rather than an oversight. The two reads answer different
 * questions: the first says what a run can skip before it starts, the second
 * says what is still outstanding now — and between them another session may
 * have banked verdicts of its own. Taking the later answer means two runs in
 * two terminals do not each mutate what the other just proved. The cost is a
 * second read of one small file; the alternative is re-proving work that is
 * already recorded.
 *
 * **Only a clean exit banks anything.** The engine is gated at the full score,
 * so exit 0 proves every mutant in its scope died.
 */
final readonly class Gate {

	/**
	 * @param Project $project
	 * @param Configuration $configuration
	 * @param Keying $keying
	 * @param Freshness $freshness
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
		private Freshness $freshness,
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
		return $this->gated(FALSE, Standing::unusable());
	}

	/**
	 * Mutates only the files whose verdict the store cannot vouch for.
	 *
	 * @return int
	 */
	public function narrowed(): int {
		$stored = $this->store->read();
		$standing = $this->freshness->of($stored);

		return $this->settled($standing, $stored) ?? $this->gated(TRUE, $standing);
	}

	/**
	 * Reports what the store claims, mutating nothing.
	 *
	 * @return int
	 */
	public function status(): int {
		$stored = $this->store->read();

		return $this->recalled($this->freshness->of($stored), $stored) ?? $this->measured();
	}

	/**
	 * A narrowed run with nothing to do, answered from the store alone.
	 *
	 * **No coverage, no engine, nothing started.** The store records what each
	 * test runs and the digest of everything the project autoloads, so when
	 * none of it has moved there is nothing a fresh report could say that the
	 * stored one does not — and generating one to be told so is the cost this
	 * cache exists to remove.
	 *
	 * @param Standing $standing
	 * @param Snapshot $stored
	 * @return int|null NULL where the store cannot answer, and the run proceeds.
	 */
	private function settled(Standing $standing, Snapshot $stored): ?int {
		if(!$standing->isSettled()) {
			return NULL;
		}

		$this->announce($standing->total, 0, []);
		$this->caution($stored);
		$this->output->line(Summary::nothingToMutate());

		return 0;
	}

	/**
	 * What the store claims, when it still describes the tree.
	 *
	 * Reported even where there is work outstanding, because the gaps are part
	 * of what it claims: a file nothing reaches all of is still where the tests
	 * last stopped, and saying so costs no coverage run.
	 *
	 * @param Standing $standing
	 * @param Snapshot $stored
	 * @return int|null
	 */
	private function recalled(Standing $standing, Snapshot $stored): ?int {
		if(!$standing->isCurrent()) {
			return NULL;
		}

		$this->announce($standing->total, 0, $standing->gaps);
		$this->caution($stored);

		return 0;
	}

	/**
	 * @return int
	 */
	private function measured(): int {
		$run = $this->scratch->create();

		try {
			$coverage = $this->cover($run, NULL);

			if($coverage !== 0) {
				return $coverage;
			}

			$this->report($this->keying->of($run->coverageXml()), Standing::unusable());

			return 0;
		} finally {
			$this->scratch->remove($run);
		}
	}

	/**
	 * Coverage, then the engine over the scope, then the bank.
	 *
	 * @param bool $narrowed Whether to mutate only what the store cannot vouch for.
	 * @param Standing $standing What the store could say before any of this ran.
	 * @return int
	 */
	private function gated(bool $narrowed, Standing $standing): int {
		$subset = $this->subsetFor($narrowed, $standing);
		$run = $this->scratch->create();

		try {
			$coverage = $this->cover($run, $subset === NULL ? NULL : $standing->tests);

			if($coverage !== 0) {
				return $coverage;
			}

			$reading = $this->keying->of($run->coverageXml(), $subset);
			$unfinished = $this->report($reading, $standing);
			$scope = $narrowed ? $unfinished : NULL;

			if($scope === []) {
				$this->output->line(Summary::nothingToMutate());

				return 0;
			}

			$status = $this->processes->run($this->engine->command($run, $scope), $this->project->root);

			if($status !== 0) {
				return $status;
			}

			$proven = $this->bankable($reading, $scope);
			$unmoved = $this->freshness->unmoved($reading->snapshot, $proven);
			$banked = $this->store->bank($reading->snapshot, $unmoved);

			$this->output->line(Summary::banked($banked, Store::PATH));

			foreach(Summary::moved(count($proven) - count($unmoved)) as $line) {
				$this->output->line($line);
			}

			return 0;
		} finally {
			$this->scratch->remove($run);
		}
	}

	/**
	 * Generates the coverage report this run works from.
	 *
	 * @param Run $run
	 * @param list<string>|null $tests The test files to run, or NULL for all of them.
	 * @return int
	 */
	private function cover(Run $run, ?array $tests): int {
		return $this->processes->run($this->coverageCommand($run, $tests), $this->project->root);
	}

	/**
	 * The coverage command with its placeholders filled in.
	 *
	 * **`{tests}` is an argument, and it expands in place** — to the test
	 * files a narrowed run needs, or to nothing at all on a run that wants the
	 * whole suite. Expanding to nothing rather than to an empty string matters:
	 * a runner handed `""` as an argument looks for a test file called nothing.
	 *
	 * @param Run $run
	 * @param list<string>|null $tests
	 * @return list<string>
	 */
	private function coverageCommand(Run $run, ?array $tests): array {
		$command = [];

		foreach($this->configuration->coverage as $argument) {
			if($argument !== Configuration::TESTS_PLACEHOLDER) {
				$command[] = str_replace(
					[Configuration::COVERAGE_PLACEHOLDER, Configuration::JUNIT_PLACEHOLDER],
					[$run->coverageXml(), $run->junit()],
					$argument,
				);

				continue;
			}

			foreach($tests ?? [] as $test) {
				$command[] = $test;
			}
		}

		return $command;
	}

	/**
	 * The paths a subset run may draw conclusions about, or NULL where the
	 * whole suite is going to run anyway.
	 *
	 * **Three things have to hold.** The run must be narrowed, the store must
	 * still describe the tree — otherwise there is no trustworthy list of
	 * tests to ask for — and the coverage command must say where the test
	 * files go. Any of them missing and the suite runs whole, which is the
	 * behaviour every project gets until it opts in.
	 *
	 * @param bool $narrowed
	 * @param Standing $standing
	 * @return list<string>|null
	 */
	private function subsetFor(bool $narrowed, Standing $standing): ?array {
		if(!$narrowed || !$standing->usable || !$this->configuration->narrowable()) {
			return NULL;
		}

		return $standing->outstanding();
	}

	/**
	 * Says how much of the project the store vouches for, and answers the rest.
	 *
	 * @param Reading $reading
	 * @param Standing $standing
	 * @return list<string> Everything that still needs the engine: stale, plus never bankable.
	 */
	private function report(Reading $reading, Standing $standing): array {
		$stale = $reading->snapshot->staleAgainst($this->store->read());
		$unproven = $reading->unproven();
		$unfinished = [...$stale, ...$unproven];
		$total = $standing->usable ? $standing->total : $reading->count();

		$this->announce($total, count($stale), $reading->gaps);
		$this->caution($reading->snapshot);

		sort($unfinished);

		return $unfinished;
	}

	/**
	 * The two things every verb says: how much is vouched for, and where the
	 * tests stop in whatever is not.
	 *
	 * @param int $total
	 * @param int $stale
	 * @param array<string, list<Unreached>> $gaps
	 * @return void
	 */
	private function announce(int $total, int $stale, array $gaps): void {
		$this->output->line(Summary::verified($total, $stale, count($gaps)));

		foreach(Summary::unreached($gaps) as $line) {
			$this->output->line($line);
		}
	}

	/**
	 * @param Snapshot $snapshot
	 * @return void
	 */
	private function caution(Snapshot $snapshot): void {
		foreach(Summary::attributionBound($snapshot->isAttributionBound()) as $line) {
			$this->output->line($line);
		}
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
