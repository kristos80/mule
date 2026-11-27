<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Config\Configuration;
use function implode;

/**
 * The command lines mule starts, and nothing else.
 *
 * Pure, so what gets run is a decision a test can read rather than a string
 * assembled somewhere a process is also being started.
 */
final readonly class Commands {

	/**
	 * @param Configuration $configuration
	 * @param string $interpreter The PHP binary to start children with.
	 * @param string $ini The parent's loaded ini file, or an empty string if it has none.
	 */
	public function __construct(
		private Configuration $configuration,
		private string $interpreter,
		private string $ini,
	) {}

	/**
	 * One PHPUnit run producing the payload mule's pipeline starts from, and the
	 * JUnit log the timings come from.
	 *
	 * **`$only` captures one test file.** That is the unit the cache is kept in,
	 * and on a warm run it is the only thing re-measured.
	 *
	 * @param string $payload Where the `.cov` goes.
	 * @param string $junit Where the JUnit log goes.
	 * @param string $only One test file, or an empty string for the whole suite.
	 * @return list<string>
	 */
	public function capture(string $payload, string $junit, string $only = ""): array {
		$command = [
			...$this->php(),
			"vendor/bin/phpunit",
			"--no-output",
			"--coverage-php={$payload}",
			"--log-junit={$junit}",
		];

		return $only === "" ? $command : [...$command, $only];
	}

	/**
	 * The engine over a report mule rendered, mutating only what is owed.
	 *
	 * **Every flag here is load-bearing**, and `docs/keying.md` says why for each:
	 * `--skip-initial-tests` because the report is handed over rather than
	 * generated (M1), `--with-timeouts` because a timed-out mutant otherwise
	 * scores as a kill (M14), both MSI floors because a zero-mutant run exits 0
	 * without them (G5). `--ignore-msi-with-no-mutations` must never appear.
	 *
	 * **The parent's ini is deliberately *not* forwarded here**, which is the one
	 * asymmetry with `capture()`. Mutation reads a report that already exists, so
	 * it needs no coverage driver — and handing it one does two things, both bad.
	 * Measured: Infection detects Xdebug and restarts itself through
	 * `composer/xdebug-handler` under a stripped ini, and the restarted process
	 * then cannot locate `vendor/bin/phpunit` at all — the run dies before a single
	 * mutant, with `Unable to locate a phpunit executable`. The same command
	 * without `-c` completes. Second, every mutant's test run would carry the
	 * driver's overhead for nothing.
	 *
	 * **An empty `$files` is no filter at all, rather than an empty one.** A cold
	 * run is asking about every source file, and naming four hundred of them is a
	 * command line where an absent flag says the same thing — whereas `--filter=`
	 * with nothing after it is a filter that matches nothing, which G1 would then
	 * read as the engine having filed no mutants against every file at once.
	 *
	 * @param string $configuration The rendered declaration.
	 * @param string $coverage The report directory.
	 * @param list<string> $files What to mutate, or nothing for all of it.
	 * @return list<string>
	 */
	public function mutate(string $configuration, string $coverage, array $files): array {
		$command = [
			$this->interpreter,
			// **`auto_prepend_file` is cleared, and that restores a guarantee rather
			// than bypassing a check.** Infection 0.35 refuses to run when one is
			// set, because a preloaded file executes before its mutant interceptor
			// can be installed — so it cannot promise the tests ran mutated code.
			// Measured: Herd sets one (`dump-loader.php`), and every run died before
			// the first mutant. Clearing it for the engine's own process lets the
			// interceptor work; Infection sets its own per mutant afterwards.
			"-d",
			"auto_prepend_file=",
			"vendor/bin/infection",
			"--configuration={$configuration}",
			"--coverage={$coverage}",
			"--skip-initial-tests",
			"--with-timeouts",
			"--threads={$this->configuration->threads}",
			"--min-msi=100",
			"--min-covered-msi=100",
			"--no-progress",
			"-n",
		];

		return $files === [] ? $command : [...$command, "--filter=" . implode(",", $files)];
	}

	/**
	 * **The parent's ini is forwarded, because a child does not inherit a driver
	 * that arrived on the command line.** `XDEBUG_MODE` in the environment is not
	 * enough: the extension has to be loaded. A child started without it writes a
	 * JUnit log, no coverage at all, and exits 1 with nothing on stdout because
	 * `--no-output` swallowed the reason.
	 *
	 * @return list<string>
	 */
	private function php(): array {
		return $this->ini === "" ? [$this->interpreter] : [$this->interpreter, "-c", $this->ini];
	}
}
