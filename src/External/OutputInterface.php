<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Somewhere to write a line of report.
 *
 * What the lines *say* is decided in {@see \Kristos80\Mule\Console\Summary},
 * which returns strings and is tested like anything else. This interface exists
 * so that deciding and printing are not the same act.
 */
interface OutputInterface {

	/**
	 * @param string $text
	 * @return void
	 */
	public function line(string $text): void;
}
