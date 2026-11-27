<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\ProgressInterface;
use Override;

/**
 * What mule said it was doing, in order — so "it told somebody before the long
 * wait" is something a test reads rather than something a person watches.
 */
final class RecordingProgress implements ProgressInterface {

	/**
	 * @var list<string>
	 */
	public array $stages = [];

	/**
	 * How many times anything reported being alive.
	 */
	public int $ticks = 0;

	/**
	 * Whether the last stage was closed.
	 */
	public bool $closed = FALSE;

	#[Override]
	public function begin(string $what): void {
		$this->stages[] = $what;
		$this->closed = FALSE;
	}

	#[Override]
	public function tick(): void {
		$this->ticks++;
	}

	#[Override]
	public function done(): void {
		$this->closed = TRUE;
	}
}
