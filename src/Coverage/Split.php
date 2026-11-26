<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * Cuts one whole-suite capture into a capture per test class.
 *
 * **This is what makes the cold path cheap.** Capturing coverage once and
 * splitting it costs 30.8 s + 2.9 s on the measured application, against 61 s
 * for capturing each test file in its own process — the same shards for half
 * the price, because a shard per process pays for a PHP bootstrap per shard.
 * See `docs/keying.md` M4 and §5.
 *
 * Two details carry the correctness:
 *
 * - **Line data is keyed by test *index*** into `testIds()`, so a shard's
 *   indices have to be renumbered against its own shorter list (M3).
 * - **The `NULL` entries are kept for every file**, including files the shard's
 *   test never touched. Those are the executable-but-unexecuted lines, and they
 *   are what makes a merged report's denominator — and therefore its coverage
 *   percentage — correct.
 */
final readonly class Split {

	/**
	 * @param Capture $capture The whole-suite capture to cut up.
	 */
	public function __construct(private Capture $capture) {}

	/**
	 * One capture per test class, keyed by class name.
	 *
	 * @return array<non-empty-string, Capture>
	 */
	public function byTestClass(): array {
		$lines = $this->capture->coverage->lineCoverage();
		$shards = [];

		foreach($this->indicesByTestClass() as $class => $indices) {
			$shards[$class] = $this->shard($lines, $indices);
		}

		return $shards;
	}

	/**
	 * Which test indices belong to each test class.
	 *
	 * A test id is `Class::method`, or `Class::method#dataset` for a case fed
	 * by a provider; both belong to the class before the first `::`.
	 *
	 * @return array<non-empty-string, array<int<0, max>, int<0, max>>> Class to a set of indices, keyed by index.
	 */
	private function indicesByTestClass(): array {
		$classes = [];

		foreach($this->capture->testIds() as $index => $id) {
			$class = strstr($id, "::", TRUE) ?: $id;
			$classes[$class][$index] = $index;
		}

		return $classes;
	}

	/**
	 * One test class's capture, with the indices renumbered against its own
	 * test list.
	 *
	 * @param array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>> $lines The whole capture's line data.
	 * @param array<int<0, max>, int<0, max>> $indices The indices this shard keeps.
	 * @return Capture
	 */
	private function shard(array $lines, array $indices): Capture {
		$ids = $this->capture->testIds();
		$local = [];
		$renumbered = [];

		foreach($indices as $index) {
			$renumbered[$index] = count($local);
			$local[] = $ids[$index];
		}

		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds($local);
		$coverage->setLineCoverage($this->linesFor($lines, $indices, $renumbered));

		return $this->capture->with(
			$coverage,
			array_intersect_key($this->capture->testResults, array_flip($local)),
			$this->capture->basePath,
		);
	}

	/**
	 * The line data as this shard sees it: every file and every line the whole
	 * capture knew about, carrying only this shard's own tests.
	 *
	 * @param array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>> $lines
	 * @param array<int<0, max>, int<0, max>> $indices
	 * @param array<int<0, max>, int<0, max>> $renumbered Whole-capture index to shard-local index.
	 * @return array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>>
	 */
	private function linesFor(array $lines, array $indices, array $renumbered): array {
		$kept = [];

		foreach($lines as $file => $numbers) {
			$kept[$file] = $this->fileLines($numbers, $indices, $renumbered);
		}

		return $kept;
	}

	/**
	 * @param array<int<1, max>, array<int<0, max>, int<1, max>>|null> $numbers
	 * @param array<int<0, max>, int<0, max>> $indices
	 * @param array<int<0, max>, int<0, max>> $renumbered
	 * @return array<int<1, max>, array<int<0, max>, int<1, max>>|null>
	 */
	private function fileLines(array $numbers, array $indices, array $renumbered): array {
		$kept = [];

		foreach($numbers as $line => $tests) {
			$kept[$line] = $tests === NULL ? NULL : $this->lineTests($tests, $indices, $renumbered);
		}

		return $kept;
	}

	/**
	 * The tests on one line that belong to this shard, renumbered.
	 *
	 * **The keys are the test indices**; the values are always `1` and mean
	 * nothing (M3).
	 *
	 * @param array<int<0, max>, int<1, max>> $tests
	 * @param array<int<0, max>, int<0, max>> $indices
	 * @param array<int<0, max>, int<0, max>> $renumbered
	 * @return array<int<0, max>, int<1, max>>
	 */
	private function lineTests(array $tests, array $indices, array $renumbered): array {
		$mine = [];

		foreach(array_keys($tests) as $index) {
			if(isset($indices[$index])) {
				$mine[$renumbered[$index]] = 1;
			}
		}

		return $mine;
	}
}
