<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Timings;

/**
 * What a coverage pass produced: the attribution, the timings, and the record of
 * what each test was seen to run.
 *
 * **Three things, not one, and that is the point.** The attribution is merged
 * from shards; the timings come from a single reference capture because summing
 * them across shards inflates them and the engine then silently discards mutants
 * that look slow (M12, M13); and the test record is what lets the *next* run
 * answer what is outstanding without coming back here at all.
 */
final readonly class Measured {

	/**
	 * @param Capture $capture The merged attribution.
	 * @param Timings $timings Per test class, from one reference capture.
	 * @param array<string, list<string>> $tests Test file to the project-relative source files it ran.
	 */
	public function __construct(
		public Capture $capture,
		public Timings $timings,
		public array $tests,
	) {}
}
