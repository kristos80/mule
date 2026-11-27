<?php
declare(strict_types=1);

namespace Kristos80\Mule\Doctor;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Coverage\Reach;
use function count;

/**
 * What an examination found.
 *
 * **It writes nothing and mutates nothing.** `doctor` answers "would a verdict
 * cache pay for itself here", and that question is answerable from one coverage
 * capture — so a project can ask it before committing a store or running the
 * engine once.
 */
final readonly class Diagnosis {

	/**
	 * @param Spread $spread How far an edit to each file reaches.
	 * @param Tier $tier Whether a cache will pay for itself, and why not when it will not.
	 * @param array<string, Footprint> $tests What each test file was seen to execute.
	 * @param list<string> $excluded The source files `mule.json` takes out of the gate.
	 * @param list<string> $attributed Excluded files the coverage report nonetheless attributes.
	 * @param array<string, Reach> $reach How much of each gated file the suite runs.
	 */
	public function __construct(
		public Spread $spread,
		public Tier $tier,
		public array $tests,
		public array $excluded,
		public array $attributed,
		public array $reach,
	) {}

	/**
	 * The gated files the suite does not reach to the configured floor.
	 *
	 * **Worth knowing before a single mutant runs**, because mule will report every
	 * one of them as unproven — and an uncovered line whose statements happen to be
	 * unmutable produces no mutant at all, so the mutation score would not have
	 * said so.
	 *
	 * @return array<string, Reach>
	 */
	public function unreached(): array {
		$unreached = [];

		foreach($this->reach as $path => $reach) {
			if(!$reach->meets) {
				$unreached[$path] = $reach;
			}
		}

		return $unreached;
	}

	/**
	 * The test files with the widest footprints, widest first.
	 *
	 * **The thing to look at when the closure is dense**, because a test that
	 * executes a large share of the project puts every file it touches into every
	 * other one's closure. Naming the files is a symptom; naming the tests is the
	 * cause.
	 *
	 * @param int $many
	 * @return array<string, int<0, max>>
	 */
	public function footprints(int $many): array {
		$widths = [];

		foreach($this->tests as $test => $footprint) {
			$widths[$test] = count($footprint->touches);
		}

		arsort($widths);

		return array_slice($widths, 0, $many, preserve_keys: TRUE);
	}
}
