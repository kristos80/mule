<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Doctor\Diagnosis;
use function count;

/**
 * What an examination says, in a form somebody decides with.
 *
 * **The figures lead and the letter follows.** Every threshold behind the tier is
 * a judgement rather than a finding, so a project at 26 % should not read as
 * categorically different from one at 24 % — and a reader who can see the numbers
 * can disagree with the line.
 *
 * **It ends with advice, including "you do not need this tool".** Two earlier
 * attempts produced a correct cache that saved nothing, on a project whose figures
 * said so before a line was written. Saying it out loud is the whole point of the
 * command.
 */
final readonly class Prognosis {

	/**
	 * How many of each list is enough to act on. Longer is a dump rather than a
	 * finding.
	 */
	private const int NAMED = 5;

	/**
	 * @param Diagnosis $diagnosis
	 * @return list<string>
	 */
	public function lines(Diagnosis $diagnosis): array {
		return [
			...$this->shape($diagnosis),
			...$this->listed("The files whose edits reach furthest", $this->reaches($diagnosis)),
			...$this->listed("The widest test footprints", $this->widths($diagnosis)),
			...$this->listed("Gated files the suite does not reach", $this->unreached($diagnosis)),
			...$this->listed("Excluded, but your tests run them", $diagnosis->attributed),
			"",
			$diagnosis->tier->advice,
		];
	}

	/**
	 * @param Diagnosis $diagnosis
	 * @return list<string>
	 */
	private function shape(Diagnosis $diagnosis): array {
		return [
			"{$diagnosis->spread->files} gated file(s), " . count($diagnosis->excluded)
			. " excluded, " . count($diagnosis->tests) . " test file(s).",
			"",
			"Tier {$diagnosis->tier->letter}: {$diagnosis->tier->because}.",
		];
	}

	/**
	 * @param Diagnosis $diagnosis
	 * @return list<string>
	 */
	private function reaches(Diagnosis $diagnosis): array {
		$named = [];

		foreach($diagnosis->spread->widest(self::NAMED) as $path => $reach) {
			$named[] = "{$path} — {$reach} file(s), " . $diagnosis->spread->share($reach) . " %";
		}

		return $named;
	}

	/**
	 * @param Diagnosis $diagnosis
	 * @return list<string>
	 */
	private function widths(Diagnosis $diagnosis): array {
		$named = [];

		foreach($diagnosis->footprints(self::NAMED) as $test => $width) {
			$named[] = "{$test} — runs {$width} file(s)";
		}

		return $named;
	}

	/**
	 * @param Diagnosis $diagnosis
	 * @return list<string>
	 */
	private function unreached(Diagnosis $diagnosis): array {
		$named = [];

		foreach($diagnosis->unreached() as $path => $reach) {
			$named[] = "{$path} — {$reach->executed} of {$reach->executable} line(s)";
		}

		return array_slice($named, 0, self::NAMED);
	}

	/**
	 * @param string $heading
	 * @param list<string> $named
	 * @return list<string>
	 */
	private function listed(string $heading, array $named): array {
		if($named === []) {
			return [];
		}

		$lines = ["", "{$heading}:"];

		foreach($named as $one) {
			$lines[] = "  {$one}";
		}

		return $lines;
	}
}
