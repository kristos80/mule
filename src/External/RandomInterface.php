<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * A name nothing can guess.
 *
 * It is behind an interface for one reason: a run's scratch directory is named
 * with it, and a test that cannot predict the name cannot assert the paths
 * mule hands to a coverage run and to the engine. How many bytes of entropy go
 * into it is a margin rather than behaviour — so it lives here, where no
 * mutation gate asks a test to tell eight bytes from nine.
 */
interface RandomInterface {

	/**
	 * A hexadecimal token, unique for practical purposes.
	 *
	 * @return string
	 */
	public function token(): string;
}
