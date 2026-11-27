<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\Failure;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * One test class's coverage, in the form it is kept on disk.
 *
 * **Only the entries that name a test are stored.** A split shard mirrors the
 * capture it came from, so 99 % of it is lines some *other* test executed, kept
 * as `[]` — measured at 8,629 entries of which 88 carried a test. Those are
 * recoverable: a complete shard set contains every line any test ran, so their
 * union is the capture. Verified on a 613-file project, thinning all 328 shards
 * and merging them reproduced the monolithic line data exactly, at 4.0 MB
 * against 46.4 MB. See `docs/keying.md` §5.
 *
 * **The digests are the whole point of the record.** A shard is reusable only
 * while its test file *and every source file it recorded coverage for* still
 * hash the same. That is stricter than "its test did not change", and the
 * difference is a correctness bug rather than an optimisation: a shard holds
 * line data for files other than its own, and reusing it after one of those
 * moved puts coverage for the old line numbers into the merged report — which
 * then reports 100 % for a method that has never run (M8).
 */
final readonly class Shard {

	/**
	 * @param non-empty-string $testClass
	 * @param array<int<0, max>, non-empty-string> $testIds Shard-local; the line data indexes into this. Positional, which `fromArray` asserts.
	 * @param array<non-empty-string, array<int<1, max>, list<int<0, max>>>> $lines Only test-bearing entries.
	 * @param array<non-empty-string, array{size: string, status: string, time: float}> $testResults
	 * @param array<string, non-empty-string> $digests Path to digest, as measured when this was captured.
	 */
	private function __construct(
		public string $testClass,
		public array $testIds,
		public array $lines,
		public array $testResults,
		public array $digests,
	) {}

	/**
	 * Thins a split capture down to what is worth keeping.
	 *
	 * @param non-empty-string $testClass
	 * @param Capture $capture One test class's capture, as `Split` produced it.
	 * @param array<string, non-empty-string> $digests The test file's digest and that of every source file below.
	 * @return self
	 */
	public static function of(string $testClass, Capture $capture, array $digests): self {
		$lines = [];

		foreach($capture->coverage->lineCoverage() as $file => $numbers) {
			$covered = self::covered($numbers);

			if($covered !== []) {
				$lines[$file] = $covered;
			}
		}

		return new self($testClass, $capture->testIds(), $lines, $capture->testResults, $digests);
	}

	/**
	 * Back into a capture `Merge` can take.
	 *
	 * The `[]` entries are not rebuilt, because nothing reads them: the union of
	 * a complete shard set is the capture either way (§5).
	 *
	 * @param Capture $template Where the build information and format come from.
	 * @param string $basePath
	 * @return Capture
	 */
	public function capture(Capture $template, string $basePath): Capture {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds($this->testIds);
		$coverage->setLineCoverage($this->hydrated());

		return $template->with($coverage, $this->testResults, $basePath);
	}

	/**
	 * Whether this shard still describes the tree, given what the files hash
	 * now.
	 *
	 * **Absent means moved.** A file mule cannot hash is not a file that stayed
	 * the same, so a digest missing from `$now` fails rather than being skipped.
	 *
	 * @param array<string, string> $now Current digests, keyed by the same paths.
	 * @return bool
	 */
	public function matches(array $now): bool {
		foreach($this->digests as $path => $digest) {
			if(($now[$path] ?? NULL) !== $digest) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			"testClass" => $this->testClass,
			"testIds" => $this->testIds,
			"lines" => $this->lines,
			"testResults" => $this->testResults,
			"digests" => $this->digests,
		];
	}

	/**
	 * **JSON, not a `require`d PHP file.** A cache entry that is executed is a
	 * cache entry that can run code, and these live in a working tree beside the
	 * sources they describe. Decoding all 328 of a real project's costs 0.07 s,
	 * so the safer format is also the free one.
	 *
	 * Reading it is `ShardFormat`'s job: validating four sections down to their
	 * indices is most of the work and none of the meaning, and together in one
	 * class they put it over the blueprint's complexity limit.
	 *
	 * @param mixed $decoded
	 * @return self
	 */
	public static function fromArray(mixed $decoded): self {
		$read = ShardFormat::read($decoded);

		return new self(
			$read["testClass"],
			$read["testIds"],
			$read["lines"],
			$read["testResults"],
			$read["digests"],
		);
	}

	/**
	 * The entries on one file's lines that name at least one test.
	 *
	 * **The keys are the test indices**; the values are always `1` and mean
	 * nothing (M3). They are dropped here, so the stored form is a plain list.
	 *
	 * @param array<int<1, max>, array<int<0, max>, int<1, max>>|null> $numbers
	 * @return array<int<1, max>, list<int<0, max>>>
	 */
	private static function covered(array $numbers): array {
		$kept = [];

		foreach($numbers as $line => $tests) {
			if(is_array($tests) && $tests !== []) {
				$kept[$line] = array_keys($tests);
			}
		}

		return $kept;
	}

	/**
	 * The stored lists back into the `index => 1` shape the engine's data
	 * structure uses.
	 *
	 * @return array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>>>
	 */
	private function hydrated(): array {
		$lines = [];

		foreach($this->lines as $file => $numbers) {
			foreach($numbers as $line => $indices) {
				$lines[$file][$line] = array_fill_keys($indices, 1);
			}
		}

		return $lines;
	}
}
