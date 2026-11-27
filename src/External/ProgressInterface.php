<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Somewhere to say what is taking a while.
 *
 * **mule's work is three long waits in a trench coat**, and without this the
 * terminal shows nothing between the prompt and the verdict: a coverage capture
 * is half a minute on a real project and the engine is several, so a run that
 * says nothing is indistinguishable from a run that has hung.
 *
 * **Behind an interface for the usual reason**, and one more: a spinner is the
 * one kind of output that must *not* appear in a CI log, so what happens to a
 * stage is a rendering decision rather than something the pipeline knows.
 */
interface ProgressInterface {

	/**
	 * Announce something about to take a while. Ends whatever came before.
	 *
	 * @param string $what
	 * @return void
	 */
	public function begin(string $what): void;

	/**
	 * Still working.
	 *
	 * **Called from inside the wait, not around it.** mule spends its time
	 * blocked on a child process, so the only place that can report liveness is
	 * the loop reading that child's output (`ProcessesInterface::run()`).
	 *
	 * @return void
	 */
	public function tick(): void;

	/**
	 * Nothing more is coming; close the last stage.
	 *
	 * @return void
	 */
	public function done(): void;
}
