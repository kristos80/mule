<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Runner\Run;

/**
 * The engine, as a command line and nothing else.
 *
 * **The engine builds a string and does not run it.** Mule's decision — which
 * files are worth mutating — is the whole of what this tool contributes, and
 * spelling "mutate these files, fail below a hundred" is all that is left. That
 * keeps process handling in one place.
 *
 * **Coverage is passed in and the initial test run skipped.** The engine refuses
 * to run without coverage, and mule has to generate it anyway to key the files;
 * generating it twice would hand back the time the cache saves.
 *
 * **The engine ships with mule**, so there is no binary to configure and no
 * version a project can pick. It is a dependency rather than a command found on
 * a path, which is what lets its version be recorded as part of what a verdict
 * means. The `run` verb is named explicitly: the configuration option has to
 * follow a command rather than precede one.
 *
 * **What varies per run is here, and what the gate *means* is not.** The scope,
 * the coverage directory and this run's tolerance for a filter that matched
 * nothing belong on a command line; the full score does not change between runs
 * and is stated in {@see Declaration} instead. Neither is configurable, and
 * neither appears in `mule.json`.
 */
final readonly class Infection {

	/** Where the engine lives, relative to whatever installed mule. */
	private const string BINARY = "./vendor/bin/infection";

	/**
	 * @param Configuration $configuration
	 */
	public function __construct(
		private Configuration $configuration,
	) {}

	/**
	 * The command that mutates a scope and fails unless every mutant died.
	 *
	 * **A narrowed run tolerates a scope with no mutants in it**, which a whole
	 * project run must not: one file can legitimately contain nothing to mutate,
	 * but a project where nothing can be mutated means the gate is measuring
	 * nothing and should say so.
	 *
	 * @param Run $run
	 * @param list<string>|null $files The files to mutate, or NULL for the whole project.
	 * @return list<string>
	 */
	public function command(Run $run, ?array $files): array {
		$command = [
			self::BINARY,
			"run",
			"--configuration={$run->engineJson()}",
			"--coverage={$run->directory}",
			"--skip-initial-tests",
			"--no-interaction",
			"--mutators=" . implode(",", $this->configuration->mutators),
			...$this->chosen(),
		];

		if($files === NULL) {
			return $command;
		}

		return [...$command, "--filter=" . implode(",", $files), "--ignore-msi-with-no-mutations"];
	}

	/**
	 * The options a project asked for, in the engine's spelling.
	 *
	 * @return list<string>
	 */
	private function chosen(): array {
		$chosen = [];

		if($this->configuration->threads !== NULL) {
			$chosen[] = "--threads={$this->configuration->threads}";
		}

		if($this->configuration->mutateUncovered) {
			$chosen[] = "--with-uncovered";
		}

		return $chosen;
	}
}
