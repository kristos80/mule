<?php
declare(strict_types=1);

namespace Kristos80\Mule\Doctor;

use Kristos80\Mule\Coverage\Soundness;
use Kristos80\Mule\Runner\Coverage;
use Kristos80\Mule\Runner\Universe;

/**
 * One examination: a coverage capture, and what it says about whether a cache
 * will pay for itself.
 *
 * **No store, no engine.** The question `doctor` answers is about the shape of the
 * test suite, and the shape is in the coverage report. Running the gate would
 * answer a different and much more expensive question.
 *
 * It does leave the shard cache behind, which is a side benefit rather than a
 * decision: the capture happened, so the next `mule` run starts warm.
 */
final readonly class Examination {

	/**
	 * @param Universe $universe
	 * @param Coverage $coverage
	 * @param Soundness $soundness
	 */
	public function __construct(
		private Universe $universe,
		private Coverage $coverage,
		private Soundness $soundness,
	) {}

	/**
	 * @return Diagnosis
	 */
	public function __invoke(): Diagnosis {
		$gated = $this->universe->sources();
		$excluded = $this->universe->excluded();
		$measured = $this->coverage->whole($this->universe->tests());
		$spread = Spread::of($measured->tests, $gated);

		return new Diagnosis(
			$spread,
			Tier::of($spread),
			$measured->tests,
			$excluded,
			$this->soundness->attributed($measured->capture, $excluded),
			$this->soundness->reach($measured->capture, $gated),
		);
	}

}
