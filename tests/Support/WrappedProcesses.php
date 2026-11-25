<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\ProcessesInterface;

/**
 * A process that was itself started with an ini, which is what a coverage
 * wrapper does: the driver is enabled for mule, and whatever mule starts has to
 * be handed the same configuration or it loses it.
 */
final readonly class WrappedProcesses implements ProcessesInterface {

	/**
	 * @param string $ini
	 */
	public function __construct(
		private string $ini,
	) {}

	/**
	 * @param list<string> $command
	 * @param string $directory
	 * @return int
	 */
	public function run(array $command, string $directory): int {
		return 0;
	}

	/**
	 * @return string
	 */
	public function iniFile(): string {
		return $this->ini;
	}
}
