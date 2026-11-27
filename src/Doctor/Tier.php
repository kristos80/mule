<?php
declare(strict_types=1);

namespace Kristos80\Mule\Doctor;

/**
 * Whether a verdict cache will pay for itself on this project, and why not when
 * it will not.
 *
 * **The letter is a convenience; the figures are the measurement.** Every
 * threshold below is a judgement rather than a finding, so `Console\Prognosis`
 * prints the numbers first and the letter after — a project at 26 % is not
 * materially different from one at 24 %, and a reader who can see both can
 * disagree with the line.
 *
 * **Tier C is usually benign, and which kind it is matters more than the letter.**
 * Measured both ways:
 *
 * - **Small project.** Dense by arithmetic: a 36-file library where almost every
 *   test runs almost everything, so one edit marks 29 of them stale. Its full gate
 *   is under a minute. The correct advice is "you do not need this tool", not
 *   "restructure your tests".
 * - **Large project with wide footprints.** A suite where single tests execute a
 *   large share of a big project. Here restructuring would pay, and the widest
 *   footprints are the place to start.
 *
 * Two earlier attempts produced a correct cache that saved nothing, on a project
 * of the first kind. The failure was never the architecture — it was measuring the
 * wrong project.
 */
final readonly class Tier {

	/**
	 * Above this share of the project, the dynamic closure is not buying anything.
	 */
	private const int DENSE = 25;

	/**
	 * A file reaching this share of the project is a hot spot worth naming, even
	 * on a project whose median is low.
	 */
	private const int HOT = 40;

	/**
	 * Below this many gated files, a dense closure is arithmetic rather than a
	 * property of the tests — and the full gate is fast enough that nothing is
	 * owed to a cache anyway.
	 */
	private const int SMALL = 100;

	/**
	 * @param string $letter "A", "B" or "C".
	 * @param string $because What the figures say.
	 * @param string $advice What to do about it.
	 */
	private function __construct(
		public string $letter,
		public string $because,
		public string $advice,
	) {}

	/**
	 * @param Spread $spread
	 * @return self
	 */
	public static function of(Spread $spread): self {
		$median = $spread->median();
		$share = $spread->share($median);

		if($share > self::DENSE) {
			return self::dense($spread, $median, $share);
		}

		return self::sparse($spread, $median, $share);
	}

	/**
	 * @param Spread $spread
	 * @param int $median
	 * @param int $share
	 * @return self
	 */
	private static function dense(Spread $spread, int $median, int $share): self {
		$figures = "the median edit re-proves {$median} of {$spread->files} file(s) — {$share} %";

		if($spread->files < self::SMALL) {
			return new self(
				"C",
				"{$figures}, which on a project this size is arithmetic rather than a property of"
				. " your tests",
				"You do not need this tool. A project of {$spread->files} files has a gate short"
				. " enough that a cache has nothing to save, and installing one would add a failure"
				. " mode and buy no time.",
			);
		}

		return new self(
			"C",
			"{$figures}, so almost any edit re-proves almost everything",
			"Nothing is cacheable as it stands, and the cause is test footprints rather than"
			. " project size. Start with the widest below: a test that executes a large share of"
			. " the project puts every file it touches into every other one's closure.",
		);
	}

	/**
	 * @param Spread $spread
	 * @param int $median
	 * @param int $share
	 * @return self
	 */
	private static function sparse(Spread $spread, int $median, int $share): self {
		$figures = "the median edit re-proves {$median} of {$spread->files} file(s) — {$share} %";
		$hot = self::hot($spread);

		if($hot === 0) {
			return new self("A", $figures, "A verdict cache will pay for itself here.");
		}

		return new self(
			"B",
			"{$figures}, but {$hot} file(s) reach " . self::HOT . " % of the project or more",
			"A verdict cache will pay for itself for most edits. The hot spots below are the"
			. " exceptions — usually a shared base class or a value object nearly everything"
			. " constructs — and an edit to one of them costs close to a full run.",
		);
	}

	/**
	 * @param Spread $spread
	 * @return int<0, max>
	 */
	private static function hot(Spread $spread): int {
		$hot = 0;

		foreach($spread->invalidates as $reach) {
			if($spread->share($reach) >= self::HOT) {
				$hot++;
			}
		}

		return $hot;
	}
}
