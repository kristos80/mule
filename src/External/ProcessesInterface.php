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
}
