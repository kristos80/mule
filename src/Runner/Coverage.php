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
	 * @param array<string, list<string>> $recorded What the store says each test file runs.
	 * @param array<string, non-empty-string> $digests Every file on disk mule cares about, and its digest.
	 * @return Measured
	 */
	public function refresh(array $recorded, array $digests): Measured {
		$this->shards->prepare();

		$tests = $recorded;

		foreach($this->dirty($recorded, $digests) as $test) {
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

		return new Measured($this->merged(array_keys($recorded)), $this->shards->readTimings(), $tests);
	}

	/**
	 * The test files the cache cannot vouch for: no shard at all, or a shard whose
	 * recorded digests have moved.
	 *
	 * **Asked of the shard rather than of the outstanding list, because the shard
	 * knows what it was measured against.** A test file is dirty when its own bytes
	 * moved or when anything it ran did, and a shard records exactly those digests
	 * — so one comparison covers both, including the case the outstanding list
	 * cannot reach: a test that covered nothing, edited to cover something. Nothing
	 * it touched is stale, because it touched nothing, and the file it now covers
	 * would stay looking unreached on every run from here on.
	 *
	 * @param array<string, list<string>> $recorded
	 * @param array<string, non-empty-string> $digests
	 * @return list<string>
	 */
	private function dirty(array $recorded, array $digests): array {
		$dirty = [];

		foreach(array_keys($recorded) as $test) {
			if(!$this->shards->has($test) || !$this->shards->read($test)->matches($digests)) {
				$dirty[] = $test;
			}
		}

		return $dirty;
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

		return Capture::of($this->filesystem->payload($payload));
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
	 * **An excluded file is left out, and that has a consequence worth stating.**
	 * Its digest is then tracked nowhere, so editing it invalidates nothing — which
	 * is the price of `exclude` meaning "not mule's to answer for". A file whose
	 * bytes can change a verdict belongs in `support` instead, where a change
	 * invalidates the store outright.
	 *
	 * @param Capture $shard
	 * @return list<string>
	 */
	private function touched(Capture $shard): array {
		$touched = [];

		foreach($shard->coverage->lineCoverage() as $file => $lines) {
			$path = "{$this->configuration->source}/{$file}";

			if($this->configuration->excludes($path)) {
				continue;
			}

			foreach($lines as $tests) {
				if(is_array($tests) && $tests !== []) {
					$touched[] = $path;

					break;
				}
			}
		}

		// **Not sorted here.** `setLineCoverage()` sorts its own keys (M19), so a
		// shard's files arrive in order and a `sort` on top of them was an
		// operation with no result behind it. The order still matters — it reaches
		// the store — and `Coverage\SplitTest` is where it is held down.
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
