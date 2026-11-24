<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Failure;

/**
 * The command line: one verb, no options.
 *
 * Everything else mule needs to know is inferred from the project or declared
 * in `mule.json`, which is a file a project commits once rather than a flag
 * every invocation has to get right.
 */
final readonly class Cli {

	/**
	 * The verb a command line asks for.
	 *
	 * @param list<string> $arguments The command line without the program name.
	 * @return Verb
	 */
	public static function verbOf(array $arguments): Verb {
		$verb = $arguments[0] ?? "";
		$extra = $arguments[1] ?? "";

		if($extra !== "") {
			throw new Failure("Unexpected argument {$extra}. " . Summary::USAGE);
		}

		return Verb::tryFrom($verb) ?? throw new Failure(self::complaintAbout($verb) . " " . Summary::USAGE);
	}

	/**
	 * @param string $verb
	 * @return string
	 */
	private static function complaintAbout(string $verb): string {
		return $verb === "" ? "No verb given." : "Unknown verb {$verb}.";
	}
}
