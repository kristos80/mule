<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Coverage\Merge;
use Kristos80\Mule\Coverage\Shard;
use Kristos80\Mule\Coverage\Split;
use Kristos80\Mule\Coverage\Timings;
use Kristos80\Mule\External\ClassesInterface;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\External\ProcessesInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Project;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use function implode;

/**
 * Getting coverage, cheaply when it can be and wholly when it must be.
 *
 * **Cold is one capture and a split, never a capture per test.** Measured on a
 * 613-file project: 30.8 s monolithic plus 2.9 s to split, against 61 s to
 * capture each of 334 test files in its own process. The same shards for half the
 * price, because a process per shard pays for a PHP bootstrap per shard (M4).
 *
 * **Warm is a capture per dirty test and the rest off disk.** One test file
 * re-measured in about a second, the cache read and merged in under a fifth of
 * one.
 */
final readonly class Coverage {

	/**
	 * @param FilesystemInterface $filesystem
	 * @param ProcessesInterface $processes
	 * @param ClassesInterface $classes
	 * @param Commands $commands
	 * @param Shards $shards
	 * @param Configuration $configuration
	 * @param Project $project
	 * @param string $scratch A directory mule may write throwaway captures into, project-relative.
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private ProcessesInterface $processes,
		private ClassesInterface $classes,
		private Commands $commands,
		private Shards $shards,
		private Configuration $configuration,
		private Project $project,
		private string $scratch,
	) {}

	/**
	 * The whole suite, in one process, split into shards afterwards.
	 *
	 * @return Measured
	 */
	public function whole(): Measured {
		$this->shards->prepare();

		$capture = $this->capture();
		$timings = Timings::from($this->filesystem->read($this->junit()));
		$tests = [];

		foreach(new Split($capture)->byTestClass() as $class => $shard) {
			$test = $this->fileOf($class);
			$touches = $this->touched($shard);

			$this->shards->write($test, Shard::of($class, $shard, $this->digestsFor($test, $touches)));

			$tests[$test] = $touches;
		}

		$this->shards->writeTimings($timings);

		return new Measured($capture, $timings, $tests);
	}

	/**
	 * The dirty test files re-measured, everything else taken off disk.
	 *
	 * @param list<string> $dirty Project-relative test files whose coverage can no longer be trusted.
	 * @param list<string> $known Every test file the store has a record of.
	 * @param array<string, list<string>> $recorded What the store says each one runs.
	 * @return Measured
	 */
	public function refresh(array $dirty, array $known, array $recorded): Measured {
		$this->shards->prepare();

		$tests = $recorded;

		foreach($dirty as $test) {
			$capture = $this->capture($test);
			$classes = $capture->testClasses();

			if($classes === []) {
				$tests[$test] = [];

				continue;
			}

			$shard = new Split($capture)->byTestClass()[$classes[0]];
			$touches = $this->touched($shard);

			$this->shards->write($test, Shard::of($classes[0], $shard, $this->digestsFor($test, $touches)));

			$tests[$test] = $touches;
		}

		return new Measured($this->merged($known), $this->shards->readTimings(), $tests);
	}

	/**
	 * Starts PHPUnit and reads back what it wrote.
	 *
	 * @param string $only One test file, or an empty string for the whole suite.
	 * @return Capture
	 */
	private function capture(string $only = ""): Capture {
		$payload = $this->project->absolute("{$this->scratch}/capture.cov");
		$command = $this->commands->capture($payload, $this->junit(), $only);
		$completed = $this->processes->run($command, $this->project->absolute(""));

		if($completed["status"] !== 0) {
			throw new Failure(
				"The suite did not pass, so there is nothing to measure. "
				. implode(" ", $command) . "\n" . $completed["output"],
			);
		}

		return Capture::of(require $payload);
	}

	/**
	 * Every shard the store knows about, reassembled.
	 *
	 * @param list<string> $known
	 * @return Capture
	 */
	private function merged(array $known): Capture {
		$shards = $this->shards->all($known);

		if($shards === []) {
			throw new Failure("The shard cache holds nothing, so there is no coverage to reassemble.");
		}

		$blank = self::blank();
		$captures = [];

		foreach($shards as $shard) {
			$captures[] = $shard->capture($blank, $this->sourceRoot());
		}

		return new Merge($blank, $captures)->into($this->sourceRoot());
	}

	/**
	 * A shard carries no build information of its own, so the first one read
	 * supplies a template declaring the format mule reads — which is the format
	 * `Capture::of()` has already asserted on the way in.
	 *
	 * @return Capture
	 */
	private static function blank(): Capture {
		return Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "",
			"codeCoverage" => new ProcessedCodeCoverageData(),
			"testResults" => [],
		]);
	}

	/**
	 * What a shard covered, in the store's spelling rather than the capture's.
	 *
	 * @param Capture $shard
	 * @return list<string>
	 */
	private function touched(Capture $shard): array {
		$touched = [];

		foreach($shard->coverage->lineCoverage() as $file => $lines) {
			foreach($lines as $tests) {
				if(is_array($tests) && $tests !== []) {
					$touched[] = "{$this->configuration->source}/{$file}";

					break;
				}
			}
		}

		sort($touched);

		return $touched;
	}

	/**
	 * The digests a shard is reusable against: its own test file, and every
	 * source file it recorded coverage for.
	 *
	 * @param string $test
	 * @param list<string> $touches
	 * @return array<string, non-empty-string>
	 */
	private function digestsFor(string $test, array $touches): array {
		$digests = [$test => $this->filesystem->digest($this->project->absolute($test))];

		foreach($touches as $touched) {
			$digests[$touched] = $this->filesystem->digest($this->project->absolute($touched));
		}

		return $digests;
	}

	/**
	 * **The port answers, the caller complains.** A covering test the project's
	 * autoloader cannot place is a test whose edits would silently stop
	 * invalidating anything, which is a failure worth a message.
	 *
	 * @param string $class
	 * @return string
	 */
	private function fileOf(string $class): string {
		$file = $this->classes->fileOf($class);

		if($file === FALSE) {
			throw new Failure(
				"The project's autoloader cannot place the covering test {$class}, so mule cannot"
				. " find its file — and a test it cannot find is a test whose edits would stop"
				. " invalidating anything.",
			);
		}

		return $this->project->relative($file);
	}

	/**
	 * @return string
	 */
	private function junit(): string {
		return $this->project->absolute("{$this->scratch}/junit.xml");
	}

	/**
	 * @return string
	 */
	private function sourceRoot(): string {
		return $this->project->absolute($this->configuration->source);
	}

}
