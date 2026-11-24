<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Coverage\Unreached;

/**
 * What one coverage report says: a verdict for every file the tests reach all
 * of, and the gap for every file they do not.
 *
 * **The split is the point.** A file nothing reaches the whole of cannot be
 * banked, because the engine never mutates the lines nobody runs — it works
 * from the report's own traces, so an unreached line produces no mutant and
 * therefore cannot fail a gate. Banking such a file would record "every mutant
 * died" about code no mutant was ever made for.
 */
final readonly class Reading {

	/**
	 * @param Snapshot $snapshot What the run proved, and what its tests run.
	 * @param array<string, list<Unreached>> $gaps Project-relative path to where the tests stop.
	 */
	public function __construct(
		public Snapshot $snapshot,
		public array $gaps,
	) {}

	/**
	 * Every file the report knows about, proven or not.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->snapshot->count() + count($this->gaps);
	}

	/**
	 * The files that cannot be banked however the engine does, sorted.
	 *
	 * @return list<string>
	 */
	public function unproven(): array {
		$paths = array_keys($this->gaps);

		sort($paths);

		return $paths;
	}
}
