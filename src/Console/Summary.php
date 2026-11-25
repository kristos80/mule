<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Coverage\Reach;
use Kristos80\Mule\Coverage\Unreached;

/**
 * Every line mule prints.
 *
 * Deciding what to say and saying it are different acts: these return strings,
 * so what mule reports is asserted like any other answer rather than captured
 * from a stream.
 */
final readonly class Summary {

	public const string USAGE = "usage: mule new|full|status";

	/**
	 * What the store vouches for.
	 *
	 * @param int $total Every file the coverage report knows about.
	 * @param int $stale Proven before, but not against what is there now.
	 * @param int $unproven Not covered in full, so never bankable.
	 * @return string
	 */
	public static function verified(int $total, int $stale, int $unproven): string {
		$verified = $total - $stale - $unproven;
		$uncovered = $unproven === 0 ? "" : ", {$unproven} not covered in full";

		return "mule: {$verified} of {$total} files verified, {$stale} stale{$uncovered}.";
	}

	/**
	 * How much of the project the tests reach, in lines.
	 *
	 * **The proportion is worked out in whole tenths of a percent**, because
	 * `0.29 * 1000` is `289.999…` in binary and a project at 29 % should not be
	 * reported at 28.9 %. Integer division also means the figure is only ever
	 * rounded down, so nothing one line short of complete can read as 100 %.
	 *
	 * **Nothing is said where nothing was measured.** A store written before
	 * mule recorded this has no lines to report, and the file counts it does
	 * have are still true.
	 *
	 * @param Reach $reach
	 * @return list<string>
	 */
	public static function coverage(Reach $reach): array {
		if(!$reach->isMeasured()) {
			return [];
		}

		$percent = number_format(intdiv($reach->executed * 1000, $reach->executable) / 10, 1);

		return ["mule: {$reach->executed} of {$reach->executable} lines covered ({$percent}%)."];
	}

	/**
	 * Said when a project asked for more covered lines than it has.
	 *
	 * **Reported after the verdicts are banked, never instead of them.** The
	 * mutation work was done and proven; discarding it because a separate
	 * assertion failed would mean paying for it again on the next run.
	 *
	 * @param int $minimum
	 * @return string
	 */
	public static function belowMinimumCoverage(int $minimum): string {
		return "mule: that is below the {$minimum}% this project asks for.";
	}

	/**
	 * Said when the engine that would prove a verdict is not the one that did.
	 *
	 * **Worth a line of its own because nothing else can explain it.** Every
	 * source file, every test and every configuration byte can be identical and
	 * the gate still answer differently, because which mutants exist is the
	 * engine's business. Without this, a version moving underneath a store
	 * reads as a project that suddenly grew survivors — which is exactly how it
	 * was found, after hours of looking at everything else.
	 *
	 * @param array<string, string> $proven The engine the store recorded.
	 * @param array<string, string> $now The engine this run would use.
	 * @return list<string> Empty where both agree, or where either is unrecorded.
	 */
	public static function engineMoved(array $proven, array $now): array {
		if($proven === [] || $now === [] || $proven === $now) {
			return [];
		}

		return ["mule: every verdict is stale because the engine moved, from "
			. self::named($proven) . " to " . self::named($now) . "."];
	}

	/**
	 * @param array<string, string> $engine
	 * @return string
	 */
	private static function named(array $engine): string {
		$named = [];

		foreach($engine as $package => $version) {
			$named[] = "{$package} {$version}";
		}

		return implode(", ", $named);
	}

	/**
	 * Said before a run that will mutate one process at a time.
	 *
	 * **Mule will not choose concurrency on a project's behalf.** Running
	 * mutants in parallel can turn a test that shares state into one that fails
	 * for the wrong reason — and a mutant killed by a flaky test is a mutant
	 * recorded as dead when nothing proved it, which is the one kind of wrong
	 * answer this tool exists to avoid. So the engine's own default stands, as
	 * `narrow` does, and the project decides.
	 *
	 * **But it should not be a surprise.** Silence here is how a six-thousand
	 * mutant run went single-threaded on a project whose own tooling had always
	 * asked for every core.
	 *
	 * @param string|null $threads
	 * @return list<string>
	 */
	public static function singleThreaded(?string $threads): array {
		return $threads === NULL
			? ["mule: mutating one process at a time; set threads in mule.json to use more."]
			: [];
	}

	/**
	 * Where the tests stop, for the files that cannot be banked because of it.
	 *
	 * **Said out loud rather than left to be inferred.** A file that is not
	 * covered in full is re-mutated on every run and never recorded, so
	 * without this it would sit in the stale count for good with no reason
	 * attached to it.
	 *
	 * @param array<string, list<Unreached>> $gaps Project-relative path to its unreached ranges.
	 * @return list<string> One line, then one per file; empty when there is no gap.
	 */
	public static function unreached(array $gaps): array {
		if($gaps === []) {
			return [];
		}

		$lines = ["mule: not banked until the tests reach them:"];

		ksort($gaps);

		foreach($gaps as $path => $unreached) {
			$lines[] = "  {$path} — " . self::described($unreached);
		}

		return $lines;
	}

	/**
	 * @param list<Unreached> $unreached
	 * @return string
	 */
	private static function described(array $unreached): string {
		return $unreached === []
			? "the report gives no method ranges"
			: implode("; ", array_map(static fn(Unreached $one): string => $one->described(), $unreached));
	}

	/**
	 * @return string
	 */
	public static function nothingToMutate(): string {
		return "mule: nothing to mutate, every file is verified against its own tests.";
	}

	/**
	 * @param int $files
	 * @param string $store
	 * @return string
	 */
	public static function banked(int $files, string $store): string {
		return "mule: {$files} file(s) recorded at the full score in {$store}.";
	}

	/**
	 * Files that changed under the gate's feet.
	 *
	 * @param int $files
	 * @return list<string> Empty when nothing moved.
	 */
	public static function moved(int $files): array {
		return $files === 0
			? []
			: ["mule: {$files} file(s) changed while the gate ran and were not recorded."];
	}

	/**
	 * Said when the coverage report credits every test with one file, so that
	 * narrowing looks stronger than the data behind it.
	 *
	 * @param bool $bounded
	 * @return list<string>
	 */
	public static function attributionBound(bool $bounded): array {
		return $bounded
			? ["mule: every test here covers exactly one file, so no change can mark another file stale — "
				. "see the README on coverage attribution."]
			: [];
	}

	/**
	 * A failure mule predicted: something about the project, its configuration
	 * or its filesystem that it cannot work with.
	 *
	 * @param string $message
	 * @return string
	 */
	public static function failed(string $message): string {
		return "mule: {$message}";
	}

	/**
	 * A fault in mule itself, which is a different thing to say.
	 *
	 * Everything mule throws on purpose is a {@see \Kristos80\Mule\Failure};
	 * anything else reaching the top is a bug here rather than a mistake in the
	 * project being gated, and saying so is the difference between a user
	 * checking their `mule.json` and a user filing an issue.
	 *
	 * @param string $class
	 * @param string $message
	 * @return string
	 */
	public static function crashed(string $class, string $message): string {
		return "mule: {$class}: {$message} — that is a fault in mule rather than in your project, so please report it.";
	}
}
