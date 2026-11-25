<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\ProcessesInterface;
use LogicException;

/**
 * A process layer that throws something mule never throws on purpose.
 *
 * It stands in for a fault in mule itself — the case the command line has to
 * report differently from a project it cannot work with, since
 * {@see \Kristos80\Mule\Failure} is the only thing mule raises deliberately.
 */
final class ThrowingProcesses implements ProcessesInterface {

	/**
	 * @param list<string> $command
	 * @param string $directory
	 * @return int
	 */
	public function run(array $command, string $directory): int {
		throw new LogicException("the coverage command was never built");
	}

	/**
	 * No ini, which is a machine with its coverage driver already loaded — and
	 * the one answer a test can state without naming a path off this machine.
	 *
	 * @return string|false
	 */
	public function iniFile(): string|false {
		return FALSE;
	}
}
