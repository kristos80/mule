<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use Kristos80\Mule\External\ShellProcesses;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;
use function sys_get_temp_dir;
use const PHP_BINARY;

/**
 * Somewhere for the poll loop to report to, counted.
 */
final class Liveness {

	public int $ticks = 0;
}

/**
 * `ShellProcesses` against real children.
 *
 * **This file exists because the wrapper stopped being one primitive.** It polls
 * now, so that something can report mule is alive during the minutes it spends
 * blocked — and a loop has a way to be wrong that `stream_get_contents` did not.
 * The trap it holds down: the first `proc_get_status()` that reports the child
 * finished also reaps it, after which `proc_close()` answers `-1`. A loop that
 * trusted `proc_close()` would read every successful run as a failure.
 */
final class ProcessTest extends TestCase {

	/**
	 * @param string $code
	 * @param Liveness|null $counter
	 * @return array{status: int, output: string}
	 */
	private function started(string $code, ?Liveness $counter = NULL): array {
		return new ShellProcesses()->run(
			[PHP_BINARY, "-r", $code],
			sys_get_temp_dir(),
			waiting: $counter === NULL ? NULL : static function () use ($counter): void {
				$counter->ticks++;
			},
		);
	}

	public function testReportsTheExitCodeOfAChildThatSucceeded(): void {
		self::assertSame(0, $this->started('exit(0);')["status"]);
	}

	/**
	 * The trap. `proc_close()` after the status call that reaped the child answers
	 * `-1`, so a run that worked would read as a run that failed.
	 *
	 * @return void
	 */
	public function testASuccessfulRunIsNotReportedAsMinusOne(): void {
		self::assertNotSame(-1, $this->started('echo "done";')["status"]);
	}

	public function testReportsTheExitCodeOfAChildThatFailed(): void {
		self::assertSame(3, $this->started('exit(3);')["status"]);
	}

	/**
	 * Both streams into one buffer, because a failure's reason is as likely to be
	 * on one as the other and mule only ever reports it whole.
	 *
	 * @return void
	 */
	public function testCollectsBothStreams(): void {
		$completed = $this->started('fwrite(STDOUT, "out"); fwrite(STDERR, "err");');

		self::assertStringContainsString("out", $completed["output"]);
		self::assertStringContainsString("err", $completed["output"]);
	}

	/**
	 * More than a pipe buffer's worth, which is where a loop that forgot to drain
	 * would deadlock rather than merely truncate.
	 *
	 * @return void
	 */
	public function testCollectsOutputLargerThanAPipeBuffer(): void {
		$completed = $this->started('echo str_repeat("x", 500000);');

		self::assertSame(500000, strlen(trim($completed["output"])));
		self::assertSame(0, $completed["status"]);
	}

	/**
	 * The point of the loop: something gets to run while the child does.
	 *
	 * @return void
	 */
	public function testReportsBeingAliveWhileTheChildRuns(): void {
		$counter = new Liveness();

		$this->started('usleep(600000);', $counter);

		self::assertGreaterThan(1, $counter->ticks);
	}

	public function testAChildThatCannotBeStartedFailsWithItsName(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("Cannot start /nonexistent/mule-probe.");

		new ShellProcesses()->run(["/nonexistent/mule-probe"], sys_get_temp_dir());
	}

	public function testRunsInTheDirectoryItIsGiven(): void {
		$completed = $this->started('echo getcwd();');

		self::assertStringContainsString(basename(sys_get_temp_dir()), $completed["output"]);
	}
}
