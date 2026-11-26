<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Somewhere to write a line of output.
 *
 * **Behind an interface so what mule says is a value a test can read.** The
 * output is the only part of a run most people ever see, and "it printed the
 * right number" is worth asserting rather than eyeballing.
 */
interface OutputInterface {

	/**
	 * @param string $line
	 * @return void
	 */
	public function line(string $line): void;
}
