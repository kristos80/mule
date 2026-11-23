<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

/**
 * The three things mule does.
 *
 * **There is deliberately no passthrough verb for ad-hoc engine runs.**
 * Mutating a filter of changed *lines*, or one class, proves nothing about the
 * files holding them — so mule could bank nothing and would be adding nothing
 * to the engine's own command line. Run the engine directly for that.
 */
enum Verb: string {

	/** The gate over only the files the store cannot vouch for. */
	case Narrowed = "new";

	/** The gate over the whole project. */
	case Full = "full";

	/** What the store claims, mutating nothing. */
	case Status = "status";
}
