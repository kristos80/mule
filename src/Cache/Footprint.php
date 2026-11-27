<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * What mule knows about one test file: the bytes it was measured at, and what it
 * was seen to execute.
 *
 * **The signature is here rather than derived, and that is the whole reason this
 * class exists.** A test file's digest otherwise reaches the store only through
 * the keys of the files it covers — so a test that covers *nothing* has its bytes
 * recorded nowhere, and editing it to start covering something moves no key at
 * all. Measured: a run in that state answers "nothing is owed" while the coverage
 * picture has changed underneath it, and goes on answering that until some
 * unrelated edit happens to trigger a measurement.
 *
 * It is not a false verdict — adding a covering test cannot un-kill a mutant —
 * but it is mule claiming the store describes this tree when it does not, and
 * that claim is the only thing the fast path has to sell.
 *
 * **And it has to be in the committed store, not in the cache.** The cheap
 * question is answered on a fresh checkout with no shards at all; a signature
 * kept beside the shards could not be compared there, so the fast path would
 * either miss the change or stop being fast.
 */
final readonly class Footprint {

	/**
	 * @param non-empty-string $signature The digest of the test file when it was last measured.
	 * @param list<string> $touches The project-relative source files it ran.
	 */
	public function __construct(
		public string $signature,
		public array $touches,
	) {}

	/**
	 * Whether this record still describes the test file on disk.
	 *
	 * **Absent means moved.** A file mule cannot hash is not a file that stayed
	 * the same, so a digest missing from `$now` fails rather than being skipped —
	 * the same rule `Coverage\Shard::matches()` applies to a shard.
	 *
	 * @param array<string, non-empty-string> $now Current digests, keyed by project-relative path.
	 * @param string $test
	 * @return bool
	 */
	public function describes(array $now, string $test): bool {
		return ($now[$test] ?? NULL) === $this->signature;
	}

	/**
	 * Whether this test was seen to execute a given source file.
	 *
	 * @param string $file
	 * @return bool
	 */
	public function covers(string $file): bool {
		return in_array($file, $this->touches, TRUE);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return ["sig" => $this->signature, "touches" => $this->touches];
	}
}
