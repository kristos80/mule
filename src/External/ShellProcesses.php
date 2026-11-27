<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Closure;
use Kristos80\Mule\Failure;
use Override;
use function fclose;
use function fread;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;

/**
 * Child processes, as PHP offers them.
 *
 * `proc_open` with a command array rather than `exec`, so nothing is quoted and
 * nothing can be re-interpreted by a shell. Both streams are read into one buffer
 * because a failure's reason is as likely to be on one as the other, and mule only
 * ever reports it whole.
 *
 * **Polled rather than waited on, so something can report that mule is alive.**
 * `stream_get_contents` on a blocking pipe returns once the child is finished,
 * which is the simpler code and leaves the terminal silent for minutes at a time.
 * Reading non-blocking in a loop costs a `stream_select` per fifth of a second and
 * gives `$waiting` somewhere to run.
 *
 * **The exit code is taken from `proc_get_status()`, not from `proc_close()`.**
 * The first status call that reports the child finished also reaps it, after which
 * `proc_close()` answers `-1` — so a loop like this that trusted `proc_close()`
 * would read every successful run as a failure. That is the one trap in this file
 * and `tests/Integration` holds it down.
 *
 * The `@` is on `proc_open` for the same reason it is in `PhpFilesystem`: it
 * announces a failure twice, once as a warning and once as `FALSE`. The warning is
 * the copy mule has no use for; the `FALSE` becomes an exception carrying the name
 * of the thing that would not start.
 *
 * Excluded from coverage and mutation alongside the other wrappers.
 */
final readonly class ShellProcesses implements ProcessesInterface {

	/**
	 * How long to wait for output before looking at the child again, in
	 * microseconds. Short enough that a spinner moves, long enough that the loop
	 * costs nothing.
	 */
	private const int POLL = 200000;

	#[Override]
	public function run(
		array $command,
		string $directory,
		array $environment = [],
		?Closure $waiting = NULL,
	): array {
		$pipes = [];
		$process = @proc_open(
			$command,
			[1 => ["pipe", "w"], 2 => ["pipe", "w"]],
			$pipes,
			$directory,
			$environment === [] ? NULL : [...getenv(), ...$environment],
		);

		if($process === FALSE) {
			throw new Failure("Cannot start " . ($command[0] ?? "a command") . ".");
		}

		stream_set_blocking($pipes[1], FALSE);
		stream_set_blocking($pipes[2], FALSE);

		$output = "";
		$status = -1;

		while(TRUE) {
			$output .= $this->readable($pipes);

			if($waiting !== NULL) {
				$waiting();
			}

			$running = proc_get_status($process);

			if(!$running["running"]) {
				$status = $running["exitcode"];

				break;
			}
		}

		$output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		return ["status" => $status, "output" => $output];
	}

	/**
	 * Whatever either pipe has to give, after waiting a moment for it.
	 *
	 * @param array<int, resource> $pipes
	 * @return string
	 */
	private function readable(array $pipes): string {
		$read = [$pipes[1], $pipes[2]];
		$write = [];
		$except = [];

		stream_select($read, $write, $except, 0, self::POLL);

		$output = "";

		foreach($read as $pipe) {
			$output .= (string) fread($pipe, 65536);
		}

		return $output;
	}
}
