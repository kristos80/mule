<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Closure;

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
	 * **`$waiting` is called while the child runs**, which is the only moment
	 * anything can report that mule is alive: a coverage capture is half a minute
	 * on a real project and the engine is several, and for all of that time mule is
	 * blocked here. What to do with the news belongs to the caller.
	 *
	 * @param list<string> $command
	 * @param string $directory The working directory to run it in.
	 * @param array<string, string> $environment Added to what the parent has.
	 * @param Closure(): void|null $waiting Called repeatedly until the child exits.
	 * @return array{status: int, output: string}
	 */
	public function run(
		array $command,
		string $directory,
		array $environment = [],
		?Closure $waiting = NULL,
	): array;
}
