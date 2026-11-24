<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * One test file, and every source file it was seen to run.
 *
 * **Both halves are needed, and the second is the one that was missing.** A
 * file's verdict rests on its own bytes and on the tests that prove it — but
 * also on everything those tests execute on the way, because a mutant in a
 * class dies or survives depending on what the code around it does. Recording
 * only "this test covers that file" leaves a run able to call a file verified
 * after changing something its own test runs straight through.
 *
 * What a test is seen to run is whatever the coverage report credits it with,
 * which is why declaring covers metadata narrows what this can know.
 */
final readonly class Touched {

	/**
	 * @param string $source The digest of the test file itself.
	 * @param list<string> $touches Project-relative source paths, sorted.
	 */
	public function __construct(
		public string $source,
		public array $touches,
	) {}

	/**
	 * Whether this test runs any of the files named.
	 *
	 * @param array<string, int> $paths A set, so that membership is a lookup.
	 * @return bool
	 */
	public function runsAnyOf(array $paths): bool {
		foreach($this->touches as $path) {
			if(isset($paths[$path])) {
				return TRUE;
			}
		}

		return FALSE;
	}
}
