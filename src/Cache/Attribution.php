<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * How much of a dependency a coverage report is able to describe.
 *
 * **A report that credits every test with exactly one file cannot describe
 * one at all.** Changing a file can then never mark another stale, because
 * nothing in the data says any two are connected — which is what covers
 * metadata does to a report. It is worth saying out loud, because the
 * narrowing then looks exactly as trustworthy as it would on a report that had
 * measured everything.
 *
 * **Its own class because it is a question about the test map**, not about the
 * verdicts the map sits beside.
 */
final readonly class Attribution {

	/**
	 * Whether every test was credited with exactly one file.
	 *
	 * Fewer than two tests is not evidence of anything, so it is not reported.
	 *
	 * @param array<string, Touched> $tests
	 * @return bool
	 */
	public static function bindsEveryTestToOneFile(array $tests): bool {
		if(count($tests) < 2) {
			return FALSE;
		}

		foreach($tests as $test) {
			if(count($test->touches) !== 1) {
				return FALSE;
			}
		}

		return TRUE;
	}
}
