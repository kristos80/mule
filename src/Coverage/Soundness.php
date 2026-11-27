<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\External\LinesInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Project;
use function implode;

/**
 * Whether a merged report describes the files as they are on disk, how much of
 * them it reaches, and which files it describes that nobody asked it to.
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
	 * How much of each gated file the report says the suite runs, asserting G3 on
	 * the way through.
	 *
	 * **Figures rather than names.** Which files fall short is derivable from them,
	 * and the figures are what the store records as the reason — "the suite reaches
	 * 18 of 20 lines" is something somebody acts on where a bare name is a list.
	 *
	 * @param Capture $merged
	 * @param list<string> $gated Project-relative source files mule is answerable for.
	 * @return array<string, Reach>
	 */
	public function reach(Capture $merged, array $gated): array {
		$recorded = $merged->coverage->lineCoverage();
		$reach = [];

		foreach($gated as $path) {
			$executable = $this->lines->executableIn($this->project->absolute($path));
			$lines = $recorded[$this->within($path)] ?? [];

			$this->assertLinesValid($path, $lines, $executable);

			$reach[$path] = Reach::of($executable, $this->executed($lines), $this->configuration->minCoverage);
		}

		return $reach;
	}

	/**
	 * The excluded files the report nonetheless attributes coverage to.
	 *
	 * **A sharp edge worth reporting rather than smoothing.** `exclude` means "not
	 * mule's to answer for", so an excluded file is left out of every footprint and
	 * its digest is tracked nowhere — which is correct for an interface and wrong
	 * for a wrapper the tests go through, whose bytes can change another file's
	 * verdict. The report knows which is which: if the suite executes it, it
	 * belongs in `support` as well.
	 *
	 * **Read off the capture rather than off the footprints**, because the
	 * footprints have already had excluded files taken out of them — which is the
	 * whole thing being checked for.
	 *
	 * @param Capture $merged
	 * @param list<string> $excluded
	 * @return list<string>
	 */
	public function attributed(Capture $merged, array $excluded): array {
		$recorded = $merged->coverage->lineCoverage();
		$attributed = [];

		foreach($excluded as $path) {
			if($this->executed($recorded[$this->within($path)] ?? []) > 0) {
				$attributed[] = $path;
			}
		}

		return $attributed;
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
	 * How many of a file's lines the report says something ran.
	 *
	 * @param array<int, mixed> $lines
	 * @return int<0, max>
	 */
	private function executed(array $lines): int {
		$executed = 0;

		foreach($lines as $tests) {
			if(is_array($tests) && $tests !== []) {
				$executed++;
			}
		}

		return $executed;
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
