<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\External\LinesInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Project;
use function count;
use function implode;
use function intdiv;

/**
 * Whether a merged report describes the files as they are on disk, and how much
 * of them it reaches.
 *
 * **G3, and the one thing the mutation gate cannot see.** An uncovered line whose
 * statements happen to be unmutable produces no mutant at all — so the engine
 * files nothing against it, the file's every mutant is killed, and it banks as
 * proven while a chunk of it was never run. Infection cannot report what it never
 * generated; counting lines can.
 *
 * **Two different kinds of answer, deliberately.** A report claiming a line the
 * file does not have is a broken cache and fails the run outright. A file the
 * suite does not reach is a finding about the project: it comes back as a name, is
 * never banked, and makes the run exit 1 rather than 2.
 */
final readonly class Soundness {

	/**
	 * @param LinesInterface $lines
	 * @param Project $project
	 * @param Configuration $configuration
	 */
	public function __construct(
		private LinesInterface $lines,
		private Project $project,
		private Configuration $configuration,
	) {}

	/**
	 * The gated files the report does not cover to the floor, asserting G3 on the
	 * way through.
	 *
	 * @param Capture $merged
	 * @param list<string> $gated Project-relative source files mule is answerable for.
	 * @return list<string>
	 */
	public function uncovered(Capture $merged, array $gated): array {
		$recorded = $merged->coverage->lineCoverage();
		$uncovered = [];

		foreach($gated as $path) {
			$executable = $this->lines->executableIn($this->project->absolute($path));
			$lines = $recorded[$this->within($path)] ?? [];

			$this->assertLinesValid($path, $lines, $executable);

			if($this->shortfall($lines, $executable)) {
				$uncovered[] = $path;
			}
		}

		return $uncovered;
	}

	/**
	 * **G3 — line validity.** A line the report treats as executable and the
	 * parser does not is a shard describing a version of the file that no longer
	 * exists, which is the one failure a coverage cache can produce on its own. G1
	 * cannot catch it: the file still parses, still produces its mutants, and still
	 * gets a score — the score is just about the wrong lines.
	 *
	 * @param string $path
	 * @param array<int, mixed> $lines
	 * @param list<int> $executable
	 * @return void
	 */
	private function assertLinesValid(string $path, array $lines, array $executable): void {
		$invalid = array_diff(array_keys($lines), $executable);

		if($invalid === []) {
			return;
		}

		throw new Failure(
			"The coverage for \"{$path}\" names line(s) " . implode(", ", $invalid) . ", which that"
			. " file does not have — so the cache is describing a version of it that is gone. Run"
			. " mule --whole. Nothing was written.",
		);
	}

	/**
	 * Whether the lines actually run fall short of the floor.
	 *
	 * **A file with nothing executable in it passes.** An interface or an enum of
	 * constants has no lines to reach, and 0 of 0 is not a gap — it is G1's
	 * business, which says so in a message naming `exclude`.
	 *
	 * @param array<int, mixed> $lines
	 * @param list<int> $executable
	 * @return bool
	 */
	private function shortfall(array $lines, array $executable): bool {
		if($executable === []) {
			return FALSE;
		}

		$executed = 0;

		foreach($lines as $tests) {
			if(is_array($tests) && $tests !== []) {
				$executed++;
			}
		}

		return intdiv($executed * 100, count($executable)) < $this->configuration->minCoverage;
	}

	/**
	 * The report spells a file relative to the source root; everything else mule
	 * does spells it relative to the project.
	 *
	 * @param string $path
	 * @return string
	 */
	private function within(string $path): string {
		$prefix = "{$this->configuration->source}/";

		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
	}
}
