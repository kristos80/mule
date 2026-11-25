<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Config\Configuration;

/**
 * The test runner, as a command line and nothing else.
 *
 * **The sibling of {@see \Kristos80\Mule\Engine\Infection}, and here for the
 * same reason.** Mule asks two programs for two things, and how each of them
 * spells its arguments is that program's business rather than the gate's. With
 * this inline in the gate, `--coverage-xml` and `--testsuite` lived next to the
 * decision about *what* to measure, and swapping the runner meant editing the
 * class that decides what is stale.
 *
 * **It covers both runners mule infers.** Pest takes the arguments PHPUnit
 * takes, so what varies is the binary — which {@see Configuration} resolves —
 * and not the spelling. Naming the class for the job rather than for either
 * tool is what keeps that true.
 *
 * **It builds a command and does not run it**, so a test states the command it
 * expects and compares it. There is nothing to stub.
 */
final readonly class Measurement {

	/**
	 * @param Configuration $configuration
	 */
	public function __construct(
		private Configuration $configuration,
	) {}

	/**
	 * The command that writes the coverage report and the timing log this run
	 * works from.
	 *
	 * **A named suite is dropped the moment particular files are named.** The
	 * files are the stricter instruction of the two — they are the tests of
	 * whatever is outstanding, whichever suite they sit in — and a runner given
	 * both a suite and a file list has no sensible reading of them.
	 *
	 * @param Run $run
	 * @param list<string>|null $tests The test files to run, or NULL for all of them.
	 * @return list<string>
	 */
	public function command(Run $run, ?array $tests): array {
		$command = [
			...$this->configuration->runner,
			"--coverage-xml={$run->coverageXml()}",
			"--log-junit={$run->junit()}",
		];

		if($tests !== NULL) {
			return [...$command, ...$tests];
		}

		if($this->configuration->tests !== NULL) {
			$command[] = "--testsuite={$this->configuration->tests}";
		}

		return $command;
	}
}
