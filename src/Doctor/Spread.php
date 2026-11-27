<?php
declare(strict_types=1);

namespace Kristos80\Mule\Doctor;

use Kristos80\Mule\Cache\Footprint;
use function array_slice;
use function count;

/**
 * How far an edit to each file reaches.
 *
 * ```
 * invalidates(X) = | { F : X ∈ deps(F) } |
 * ```
 *
 * **This is the number that decides whether a cache is worth having**, and it is
 * measurable before anything is installed. Two failed attempts produced a correct
 * cache that saved nothing, on a project where this figure was 81 %.
 *
 * **A distribution, not a median.** The spread is wide: on a real application the
 * median edit reaches 11 of 404 files and an edit to the shared base class reaches
 * 215. A single figure hides which of those a given change will be, so the median
 * is reported beside the widest rather than instead of them.
 */
final readonly class Spread {

	/**
	 * @param array<string, int<0, max>> $invalidates Gated file to how many gated files an edit to it reaches.
	 * @param int<0, max> $files How many gated files there are.
	 */
	private function __construct(
		public array $invalidates,
		public int $files,
	) {}

	/**
	 * **Computed from what each test executes, which is the same relation a key
	 * rests on.** A file `F` is in `G`'s closure when some test runs both of them,
	 * so the files an edit to `F` reaches are the union of the footprints of every
	 * test that runs `F` — the edge runs through the shared test, not through the
	 * source.
	 *
	 * @param array<string, Footprint> $tests
	 * @param list<string> $gated
	 * @return self
	 */
	public static function of(array $tests, array $gated): self {
		$wanted = array_flip($gated);
		$invalidates = [];

		foreach($gated as $file) {
			$invalidates[$file] = count(self::closure($tests, $wanted, $file));
		}

		arsort($invalidates);

		return new self($invalidates, count($gated));
	}

	/**
	 * The middle of the distribution.
	 *
	 * **The lower of the two middles on an even count**, because a figure somebody
	 * decides with should not be a number no file actually has — and sorted
	 * ascending rather than read off the descending list the widest are taken
	 * from, which on an even count is a different number.
	 *
	 * @return int<0, max>
	 */
	public function median(): int {
		// `sort()` reindexes, so wrapping this in `array_values()` would be an
		// operation with no result behind it — and the gate said so.
		$counts = $this->invalidates;
		sort($counts);

		return $counts === [] ? 0 : $counts[intdiv(count($counts) - 1, 2)];
	}

	/**
	 * The files whose edits reach furthest, widest first.
	 *
	 * @param int $many
	 * @return array<string, int<0, max>>
	 */
	public function widest(int $many): array {
		return array_slice($this->invalidates, 0, $many, preserve_keys: TRUE);
	}

	/**
	 * What share of the project a given reach is, as a percentage.
	 *
	 * @param int<0, max> $reach
	 * @return int<0, max>
	 */
	public function share(int $reach): int {
		return $this->files === 0 ? 0 : intdiv($reach * 100, $this->files);
	}

	/**
	 * Every gated file some test runs alongside the one given, itself included.
	 *
	 * **A set with no values of its own.** An earlier version wrote `TRUE` against
	 * each key, which the gate showed to be a value nothing reads: flipping the
	 * touch list and intersecting it with what is gated says the same thing without
	 * inventing one.
	 *
	 * @param array<string, Footprint> $tests
	 * @param array<string, int> $wanted
	 * @param string $file
	 * @return array<string, int>
	 */
	private static function closure(array $tests, array $wanted, string $file): array {
		$closure = [];

		foreach($tests as $footprint) {
			if($footprint->covers($file)) {
				$closure += array_intersect_key(array_flip($footprint->touches), $wanted);
			}
		}

		return $closure;
	}
}
