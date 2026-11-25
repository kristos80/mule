<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Running a command and getting its exit status.
 *
 * Mule's two long operations — a coverage run and a mutation run — are both one
 * of these, and their output belongs to the terminal rather than to mule: a
 * gate that swallows an engine's progress and reprints it at the end has turned
 * a five-minute wait into a five-minute silence.
 */
interface ProcessesInterface {

	/**
	 * Runs a command to completion with this process's streams, and answers its
	 * exit status.
	 *
	 * @param list<string> $command The program and its arguments, unsplit.
	 * @param string $directory The working directory to run it in.
	 * @return int
	 */
	public function run(array $command, string $directory): int;

	/**
	 * The configuration file this process's own interpreter was started with,
	 * or FALSE where it was started with none.
	 *
	 * **A coverage driver is enabled for an interpreter, and often by an ini
	 * rather than by a binary.** A project that turns one on by wrapping mule —
	 * a Herd or PHIVE wrapper, an ini switched on for one command — gets it for
	 * mule's own process, and a child started as plain `PHP_BINARY` is handed
	 * no ini at all and loses the driver with it. Measured: under such a
	 * wrapper the parent loads `.../debug/debug.ini` and has Xdebug, and its
	 * child loads nothing and has not.
	 *
	 * **Returned rather than resolved**, because "there is no ini" is a case
	 * the caller has to decide about, and the command line that follows from it
	 * belongs with the code that builds command lines.
	 *
	 * @return string|false
	 */
	public function iniFile(): string|false;
}
