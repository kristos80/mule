<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Coverage\Shard;
use Kristos80\Mule\Coverage\Timings;
use Kristos80\Mule\External\FilesystemInterface;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * The shard cache, and the reference timings beside it.
 *
 * **Machine-local, and it ignores itself.** The cache for a 613-file project is
 * 2.1 MB of shards; the store beside it is committed. A `.gitignore` carrying `*`
 * is written into the cache directory on first use, so `git add .mule` stages the
 * store and nothing else — which was verified rather than assumed.
 *
 * **One file per test class, JSON.** A cache entry that is executed is a cache
 * entry that can run code, and these live in a working tree beside the sources
 * they describe. Reading all 328 of a real project's costs 0.07 s, so the safer
 * format is also the free one.
 */
final readonly class Shards {

	private const string IGNORE = "*\n";

	/**
	 * @param FilesystemInterface $filesystem
	 * @param string $directory The cache, absolute.
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private string $directory,
	) {}

	/**
	 * @return void
	 */
	public function prepare(): void {
		if(!$this->filesystem->isDirectory($this->directory)) {
			$this->filesystem->createDirectory($this->directory);
		}

		$this->filesystem->write("{$this->directory}/.gitignore", self::IGNORE);
	}

	/**
	 * **Keyed by test file, not by test class.** The store speaks in
	 * project-relative paths and so does the universe on disk; a cache keyed by
	 * class would need the autoloader consulted before it could answer whether it
	 * holds something. The class is data inside the shard, where it belongs.
	 *
	 * @param string $test
	 * @return bool
	 */
	public function has(string $test): bool {
		return $this->filesystem->exists($this->at($test));
	}

	/**
	 * @param string $test
	 * @return Shard
	 */
	public function read(string $test): Shard {
		return Shard::fromArray(json_decode(
			$this->filesystem->read($this->at($test)),
			associative: TRUE,
			flags: JSON_THROW_ON_ERROR,
		));
	}

	/**
	 * @param string $test
	 * @param Shard $shard
	 * @return void
	 */
	public function write(string $test, Shard $shard): void {
		$this->filesystem->write(
			$this->at($test),
			(string) json_encode($shard->toArray(), JSON_THROW_ON_ERROR),
		);
	}

	/**
	 * The shards for the test files named, skipping any the cache does not hold.
	 *
	 * A test file with no shard is the covers-nothing case (M16): a class that
	 * executes no instrumented source is absent from `testIds()`, so splitting
	 * produces nothing for it. Harmless to merge nothing; dangerous only if it
	 * were inferred rather than passed over deliberately.
	 *
	 * @param list<string> $tests
	 * @return list<Shard>
	 */
	public function all(array $tests): array {
		$shards = [];

		foreach($tests as $test) {
			if($this->has($test)) {
				$shards[] = $this->read($test);
			}
		}

		return $shards;
	}

	/**
	 * @return bool
	 */
	public function hasTimings(): bool {
		return $this->filesystem->exists($this->timings());
	}

	/**
	 * @return Timings
	 */
	public function readTimings(): Timings {
		return Timings::from($this->filesystem->read($this->timings()));
	}

	/**
	 * **Stored as the report Infection reads**, rather than as a map, so there is
	 * one spelling of it and no second encoder to disagree with the first.
	 *
	 * @param Timings $timings
	 * @return void
	 */
	public function writeTimings(Timings $timings): void {
		$this->filesystem->write($this->timings(), $timings->junit());
	}

	/**
	 * @param string $test
	 * @return string
	 */
	private function at(string $test): string {
		return "{$this->directory}/" . str_replace(["/", "\\"], "_", $test) . ".json";
	}

	/**
	 * @return string
	 */
	private function timings(): string {
		return "{$this->directory}/timings.xml";
	}
}
