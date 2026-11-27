<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Runner\Finding;
use function count;

/**
 * What a run says for itself.
 *
 * **A value, not a side effect.** The output is the only part of a run most
 * people ever see, so "it printed the right number" is worth asserting rather
 * than eyeballing — and a formatter that writes nowhere can be checked without a
 * process.
 *
 * **Every state is named, never implied by absence.** A file missing from the
 * output is indistinguishable from a file nobody thought about, which is exactly
 * how a mutable file stops being gated without anybody noticing. So the counts
 * add up to the tree on purpose: gated plus excluded is every source file there
 * is.
 */
final readonly class Summary {

	/**
	 * @param Finding $finding
	 * @param list<string> $excluded The source files `mule.json` takes out of the gate.
	 * @return list<string>
	 */
	public function lines(Finding $finding, array $excluded): array {
		return [
			...$this->what($finding),
			"",
			...$this->counts($finding, $excluded),
			...$this->listed("Not proven, found by this run", $finding->escaped()),
			...$this->listed("Not proven, and was not before this run either", $this->inherited($finding)),
			...$this->listed("Not banked, edited while the engine ran", $finding->abandoned),
			"",
			$finding->holds()
				? "Proven: every gated file, every mutant killed."
				: "NOT PROVEN: " . count($finding->store->unproven) . " file(s) have mutants nothing killed.",
		];
	}

	/**
	 * @param Finding $finding
	 * @return list<string>
	 */
	private function what(Finding $finding): array {
		if(!$finding->measured) {
			return [
				"Nothing is owed, so nothing was measured — " . $finding->standing->because . ".",
			];
		}

		if(!$finding->standing->usable) {
			return [
				"Everything was re-proven, because " . $finding->standing->because . ".",
				count($finding->examined) . " file(s) handed to the engine.",
			];
		}

		return [
			count($finding->standing->outstanding) . " file(s) were owed; " . count($finding->examined)
			. " were handed to the engine.",
		];
	}

	/**
	 * @param Finding $finding
	 * @param list<string> $excluded
	 * @return list<string>
	 */
	private function counts(Finding $finding, array $excluded): array {
		return [
			"  proven      " . count($finding->store->verdicts)
			. " file(s), " . $finding->store->mutants() . " mutant(s) killed",
			"  not proven  " . count($finding->store->unproven) . " file(s)",
			"  excluded    " . count($excluded) . " file(s), not gated at all",
		];
	}

	/**
	 * The files that were already unproven before this run looked at anything.
	 *
	 * **Separated from what this run found, because they call for different
	 * things.** "You have just broken this" is a message somebody acts on; "this
	 * has been broken since before you started" is a backlog. One list for both
	 * makes the first invisible inside the second.
	 *
	 * @param Finding $finding
	 * @return array<string>
	 */
	private function inherited(Finding $finding): array {
		// Not reindexed: the keys reach nothing but a `foreach`, and the gate
		// proved an `array_values` here to be an operation with no result behind it.
		return array_diff($finding->store->unproven, $finding->escaped());
	}

	/**
	 * @param string $heading
	 * @param array<string> $paths Keys are not read, only the names.
	 * @return list<string>
	 */
	private function listed(string $heading, array $paths): array {
		if($paths === []) {
			return [];
		}

		$lines = ["", "{$heading}:"];

		foreach($paths as $path) {
			$lines[] = "  {$path}";
		}

		return $lines;
	}
}
