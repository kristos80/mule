<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Kristos80\Mule\Failure;
use Override;
use function proc_close;
use function proc_open;
use function stream_get_contents;

/**
 * Child processes, as PHP offers them.
 *
 * `proc_open` with a command array rather than `exec`, so nothing is quoted and
 * nothing can be re-interpreted by a shell. Both streams are read into one
 * buffer because a failure's reason is as likely to be on one as the other, and
 * mule only ever reports it whole.
 *
 * Excluded from coverage and mutation alongside the other wrappers.
 */
final readonly class ShellProcesses implements ProcessesInterface {

	#[Override]
	public function run(array $command, string $directory, array $environment = []): array {
		$pipes = [];
		$process = proc_open(
			$command,
			[1 => ["pipe", "w"], 2 => ["pipe", "w"]],
			$pipes,
			$directory,
			$environment === [] ? NULL : [...getenv(), ...$environment],
		);

		if($process === FALSE) {
			throw new Failure("Cannot start " . ($command[0] ?? "a command") . ".");
		}

		$output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		return ["status" => proc_close($process), "output" => $output];
	}
}
