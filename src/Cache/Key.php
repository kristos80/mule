<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * What one file's verdict rests on.
 *
 * A mutation verdict is trustworthy only while everything that could change it
 * is unchanged, and that is four things: the file, the tests that cover it, every
 * source file those tests execute, and the toolchain (`Toolchain`).
 *
 * **`deps` is deliberately wider than "files this one references".** If one test
 * class covers both `Gate.php` and `Decoded.php`, then `Decoded.php`'s mutants
 * are run against that test, whose behaviour depends on `Gate.php` — so a change
 * to `Gate.php` can flip a `Decoded.php` verdict even though `Decoded.php` never
 * mentions `Gate`. The dependency runs through the shared *test*, not through the
 * source.
 *
 * That breadth is not a conservatism knob. It *is* the difference between a
 * project where one edit invalidates 2.7 % of verdicts and one where it
 * invalidates 81 % — the same rule over two test-suite shapes, measured. Any
 * proposal to narrow it should be checked against that pair of numbers first,
 * because a key that leaves out shared-test edges buys its granularity by
 * banking verdicts it cannot justify. See `docs/keying.md` §2 and §4.
 *
 * **The key is a comparison value, never the only record.** The store keeps the
 * maps it was computed from, so a failure can name which input moved and
 * `doctor` can report a distribution rather than a verdict. Correctness without
 * granularity is the failure mode that looks like success.
 */
final readonly class Key {

	/**
	 * @param string $path The file the verdict is about, project-relative.
	 * @param non-empty-string $digest Its own bytes.
	 * @param array<string, non-empty-string> $deps Every source file the covering tests execute, and its digest.
	 * @param array<string, non-empty-string> $tests Every covering test file, and its digest.
	 * @param non-empty-string $toolchain `Toolchain::of()`.
	 * @return non-empty-string
	 */
	public static function of(
		string $path,
		string $digest,
		array $deps,
		array $tests,
		string $toolchain,
	): string {
		ksort($deps);
		ksort($tests);

		// **Labelled sections, so a file cannot change role unnoticed.** The same
		// path appearing as a dependency rather than as a covering test is a
		// different claim, and an unlabelled union of the two maps could not tell
		// them apart.
		return Toolchain::digest([
			"file" => [$path, $digest],
			"deps" => $deps,
			"tests" => $tests,
			"toolchain" => $toolchain,
		]);
	}
}
