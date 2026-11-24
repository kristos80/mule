<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\ProcessesInterface;

/**
 * The two child processes mule starts, as a test can watch them: the coverage
 * run, which *writes a report* where it was told to, and the engine, which does
 * not.
 *
 * Writing the report is the whole reason this is not a plain spy. Mule's
 * decisions are made over a report a previous process produced, so a test that
 * cannot produce one cannot drive the gate at all — and the directory it lands
 * in is the one mule invented for this run, which is what asserting the
 * substituted command line is worth.
 */
final class CoveringProcesses implements ProcessesInterface {

	/** @var list<array{command: list<string>, directory: string}> */
	public array $runs = [];

	/**
	 * @param InMemoryFilesystem $filesystem Where the report is written.
	 * @param array<string, list<string>> $covered Source path, relative to the source root, to its covering tests.
	 * @param string $sourceRoot The absolute directory the report's own paths are relative to.
	 * @param list<int> $statuses One exit status per run, in order; 0 once they run out.
	 * @param array<string, array{executable: int, executed: int, methods: list<array{name: string, start: int, end: int, executable: int, executed: int}>}> $gaps
	 *        Source paths the tests do not reach all of, keyed as in $covered.
	 * @param array<string, string> $duringEngine Absolute path to contents, written while the engine runs.
	 */
	public function __construct(
		private InMemoryFilesystem $filesystem,
		private array $covered = [],
		private string $sourceRoot = "/project/src",
		private array $statuses = [],
		private array $gaps = [],
		private array $duringEngine = [],
	) {}

	/**
	 * @param list<string> $command
	 * @param string $directory
	 * @return int
	 */
	public function run(array $command, string $directory): int {
		$this->runs[] = ["command" => $command, "directory" => $directory];
		$status = array_shift($this->statuses) ?? 0;
		$covering = FALSE;

		foreach($command as $argument) {
			$covering = $this->cover($argument, $status) || $covering;
		}

		if(!$covering) {
			$this->edit();
		}

		return $status;
	}

	/**
	 * The command line of one run.
	 *
	 * @param int $index
	 * @return list<string>
	 */
	public function command(int $index): array {
		return $this->runs[$index]["command"] ?? [];
	}

	/**
	 * Where one run was started.
	 *
	 * @param int $index
	 * @return string
	 */
	public function directory(int $index): string {
		return $this->runs[$index]["directory"] ?? "";
	}

	/**
	 * How many processes were started.
	 *
	 * @return int
	 */
	public function count(): int {
		return count($this->runs);
	}

	/**
	 * Writes the report, where the argument is the one naming its directory and
	 * the run is one that succeeded.
	 *
	 * @param string $argument
	 * @param int $status
	 * @return bool Whether this argument was the one naming the report.
	 */
	private function cover(string $argument, int $status): bool {
		$flag = "--coverage-xml=";

		if(!str_starts_with($argument, $flag)) {
			return FALSE;
		}

		if($status !== 0) {
			return TRUE;
		}

		$directory = substr($argument, strlen($flag));

		$this->filesystem->given("{$directory}/index.xml", CoverageXml::index($this->sourceRoot));

		foreach($this->covered as $path => $coveringTests) {
			$gap = $this->gaps[$path] ?? NULL;
			$report = $gap === NULL
				? CoverageXml::file(basename($path), dirname("/{$path}"), $coveringTests)
				: CoverageXml::partialFile(
					basename($path),
					dirname("/{$path}"),
					$coveringTests,
					$gap["executable"],
					$gap["executed"],
					$gap["methods"],
				);

			$this->filesystem->given("{$directory}/{$path}.xml", $report);
		}

		return TRUE;
	}

	/**
	 * Somebody saving a file while the gate is mid-run.
	 *
	 * @return void
	 */
	private function edit(): void {
		foreach($this->duringEngine as $path => $contents) {
			$this->filesystem->given($path, $contents);
		}
	}
}
