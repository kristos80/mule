<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Doctor\Examination;
use Kristos80\Mule\Runner\Run;
use Kristos80\Mule\Runner\Universe;

/**
 * The things a command needs, built once.
 *
 * **Built once on purpose.** A composition root that answered `run()` and
 * `universe()` separately would read `mule.json` twice and resolve the toolchain
 * twice, and two reads of a file somebody may be editing can disagree — so the
 * wiring happens in one pass and hands over what it made.
 */
final readonly class Assembly {

	/**
	 * @param Run $run
	 * @param Examination $examination
	 * @param Universe $universe
	 */
	public function __construct(
		public Run $run,
		public Examination $examination,
		public Universe $universe,
	) {}
}
