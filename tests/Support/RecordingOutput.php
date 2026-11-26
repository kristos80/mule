<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\OutputInterface;
use Override;
use function implode;

/**
 * Output as a list of lines, so what mule says is something a test reads rather
 * than something a person eyeballs.
 */
final class RecordingOutput implements OutputInterface {

	/**
	 * @var list<string>
	 */
	public array $lines = [];

	#[Override]
	public function line(string $line): void {
		$this->lines[] = $line;
	}

	/**
	 * @return string
	 */
	public function text(): string {
		return implode("\n", $this->lines);
	}
}
