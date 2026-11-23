<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\OutputInterface;

/**
 * Every line mule printed, kept for an assertion.
 */
final class RecordingOutput implements OutputInterface {

	/** @var list<string> */
	public array $lines = [];

	/**
	 * @param string $text
	 * @return void
	 */
	public function line(string $text): void {
		$this->lines[] = $text;
	}

	/**
	 * Everything printed, as one block.
	 *
	 * @return string
	 */
	public function text(): string {
		return implode("\n", $this->lines);
	}
}
