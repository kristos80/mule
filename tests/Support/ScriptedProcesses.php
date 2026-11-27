<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Closure;
use Kristos80\Mule\External\ProcessesInterface;
use Override;

/**
 * Child processes a test answers for itself.
 *
 * **The answer is a closure, not a value, because the interesting part of
 * starting a child is the file it leaves behind.** PHPUnit writes a capture and
 * the engine writes a log, and an answer can put either into the in-memory
 * filesystem exactly as the real process would put it on disk.
 *
 * One closure rather than a queue: a test that needs to tell two commands apart
 * reads the command it was handed, which is also the thing worth asserting.
 */
final class ScriptedProcesses implements ProcessesInterface {

	/**
	 * Every command started, in order, so a test can assert the flags rather than
	 * trust them.
	 *
	 * @var list<array{command: list<string>, directory: string}>
	 */
	public array $started = [];

	/**
	 * @param Closure(list<string>): array{status: int, output: string}|null $answer
	 */
	public function __construct(
		private ?Closure $answer = NULL,
	) {}

	/**
	 * **An unanswered call succeeds silently.** A test that cares what a process
	 * did says so; one that does not should not have to arrange a success to get
	 * past it.
	 */
	#[Override]
	public function run(
		array $command,
		string $directory,
		array $environment = [],
		?Closure $waiting = NULL,
	): array {
		$this->started[] = ["command" => $command, "directory" => $directory];

		// **Called twice, because a real child is polled more than once.** A double
		// that never called it would let a caller forget to pass one.
		if($waiting !== NULL) {
			$waiting();
			$waiting();
		}

		return $this->answer === NULL ? ["status" => 0, "output" => ""] : ($this->answer)($command);
	}
}
