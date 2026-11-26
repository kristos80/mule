<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Child processes, behind a seam.
 *
 * mule's work is done by two other programs, so the only way it can be
 * deterministic is if starting them is the one unpredictable thing and
 * everything around it is a decision. What command to build, from what, and what
 * to make of the exit code all live on this side.
 */
interface ProcessesInterface {

	/**
	 * Runs a command to completion and reports what happened.
	 *
	 * **The command is a list, never a string.** A string would have to be
	 * quoted, and quoting a path is how a directory with a space in it becomes a
	 * different directory.
	 *
	 * **The environment is given rather than inherited selectively.** A coverage
	 * driver that arrived on the parent's command line is not in a child's, so
	 * what a child needs has to be passed deliberately (see the differential).
	 *
	 * @param list<string> $command
	 * @param string $directory The working directory to run it in.
	 * @param array<string, string> $environment Added to what the parent has.
	 * @return array{status: int, output: string}
	 */
	public function run(array $command, string $directory, array $environment = []): array;
}
