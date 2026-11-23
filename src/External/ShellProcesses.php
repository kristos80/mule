<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use RuntimeException;

/**
 * Real child processes, inheriting this one's streams so that a test runner's
 * progress and an engine's report appear as they happen.
 *
 * The command goes to `proc_open()` as an array, so nothing is handed to a
 * shell to re-split: a filter listing several hundred paths has no quoting for
 * a shell to get wrong.
 */
final readonly class ShellProcesses implements ProcessesInterface {

	/** The three file descriptors every process is born with, by number. */
	private const array DESCRIPTORS = [0 => STDIN, 1 => STDOUT, 2 => STDERR];

	/**
	 * @param list<string> $command
	 * @param string $directory
	 * @return int
	 */
	public function run(array $command, string $directory): int {
		$pipes = [];
		$process = @proc_open($command, self::DESCRIPTORS, $pipes, $directory);

		if($process === FALSE) {
			throw new RuntimeException("Cannot run " . implode(" ", $command) . ".");
		}

		return proc_close($process);
	}
}
