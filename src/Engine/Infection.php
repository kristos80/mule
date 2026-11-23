<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Kristos80\Mule\Runner\Run;

/**
 * Infection, as a command line and nothing else.
 *
 * **The engine builds a string and does not run it.** Mule's decision — which
 * files are worth mutating — is the whole of what this tool contributes, and
 * spelling "mutate these files, fail below a hundred" is all that is left. That
 * keeps it testable with the engine not installed, and keeps process handling in
 * one place.
 *
 * **Coverage is passed in and the initial test run skipped.** Infection refuses
 * to run without coverage, and mule has to generate it anyway to key the files;
 * generating it twice would hand back the time the cache saves.
 *
 * **The binary is named, never required.** Infection and a current PHPUnit
 * cannot always share a `composer.json` — it pins `sebastian/diff` and so does
 * the test runner — so projects in that position install it globally or with
 * PHIVE, and mule treats it as a command rather than a dependency it autoloads.
 */
final readonly class Infection {

	/**
	 * @param string $binary
	 * @param list<string> $options Project options, such as `--threads=max`.
	 */
	public function __construct(
		private string $binary,
		private array $options = [],
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
			$this->binary,
			"--coverage={$run->directory}",
			"--skip-initial-tests",
			"--min-msi=100",
			"--min-covered-msi=100",
			...$this->options,
		];

		if($files === NULL) {
			return $command;
		}

		return [...$command, "--filter=" . implode(",", $files), "--ignore-msi-with-no-mutations"];
	}
}
