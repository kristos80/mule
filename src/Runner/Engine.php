<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Coverage\Report;
use Kristos80\Mule\Engine\Declaration;
use Kristos80\Mule\Engine\Outcome;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\External\ProcessesInterface;
use Kristos80\Mule\External\ProgressInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Project;
use function count;
use function implode;
use function json_decode;
use const JSON_THROW_ON_ERROR;

/**
 * Infection, over a report mule rendered.
 *
 * **The report is handed over, not generated.** `--skip-initial-tests` means the
 * engine runs no test suite of its own and reads whatever is in the coverage
 * directory — which is the whole point, and also the reason G1 exists: a report
 * whose covered lines have moved makes a file look unreached, and an unreached
 * file generates no mutants and vanishes from the log rather than failing.
 *
 * **The exit status is not consulted.** The engine exits non-zero for an escaped
 * mutant, which is a verdict rather than an error, and exits zero in cases mule
 * refuses to accept (M14). What is read instead is the JSON log, and its absence
 * is the only thing treated as the engine having failed.
 */
final readonly class Engine {

	/**
	 * @param FilesystemInterface $filesystem
	 * @param ProcessesInterface $processes
	 * @param Commands $commands
	 * @param Report $report
	 * @param Declaration $declaration
	 * @param Project $project
	 * @param ProgressInterface $progress
	 * @param string $scratch Project-relative; the declaration resolves its own paths against the project root (M11).
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private ProcessesInterface $processes,
		private Commands $commands,
		private Report $report,
		private Declaration $declaration,
		private Project $project,
		private ProgressInterface $progress,
		private string $scratch,
	) {}

	/**
	 * @param Measured $measured
	 * @param list<string> $files What to mutate, or nothing for all of it.
	 * @return Outcome
	 */
	public function over(Measured $measured, array $files): Outcome {
		$log = $this->project->absolute("{$this->scratch}/outcome.json");
		$declaration = $this->project->absolute(Declaration::FILE);
		$coverage = $this->project->absolute("{$this->scratch}/coverage");

		// **Last run's log goes first.** Its path is digested into the cache key
		// and so cannot be made unique per run, which means a run whose engine
		// died on startup would otherwise read yesterday's verdicts and bank them
		// against today's keys.
		$this->filesystem->remove($log);

		$this->progress->begin("Rendering the report the engine reads");
		$this->report->write($measured->capture, $measured->timings, $coverage);
		$this->filesystem->write($declaration, $this->declaration->rendered());

		$this->progress->begin(
			"Mutating " . ($files === [] ? "every gated file" : count($files) . " file(s)"),
		);

		$command = $this->commands->mutate($declaration, $coverage, $files);
		$completed = $this->processes->run(
			$command,
			$this->project->absolute(""),
			waiting: $this->progress->tick(...),
		);

		if(!$this->filesystem->exists($log)) {
			throw new Failure(
				"The engine wrote no verdicts, so there is nothing to check and nothing was"
				. " written.\n" . implode(" ", $command) . "\n" . $completed["output"],
			);
		}

		return Outcome::of(
			json_decode($this->filesystem->read($log), associative: TRUE, flags: JSON_THROW_ON_ERROR),
			fn (string $path): string => $this->project->relative($path),
		);
	}
}
