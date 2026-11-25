<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Kristos80\Mule\Json\Decoded;

/**
 * What a run proved, file by file, read from the engine's own log.
 *
 * **This is what lets a failing run keep the work it did.** The engine reports
 * one verdict for a whole scope, and mule used to discard the lot on a non-zero
 * exit: one surviving mutant in one file and every other file in the scope went
 * unrecorded. On a project not already at the full score that meant nothing
 * could ever be banked, so the cache could only help a project that did not
 * need it.
 *
 * **A file is proven by evidence, not by the absence of blame.** It has to show
 * a mutant the engine counted towards the score and none it did not — rather
 * than merely failing to appear among the failures. The difference matters
 * exactly when it is most dangerous: an engine that died halfway leaves a log
 * naming neither the files it never reached nor any survivor in them, and
 * subtracting blame from the scope would record verdicts for files nothing had
 * mutated.
 *
 * **Errors and timeouts are not survival.** The engine counts a mutant that
 * crashed, or that the clock killed, towards the score exactly as it counts one
 * an assertion killed, so mule does too — otherwise it would refuse to record
 * files the engine was satisfied with.
 *
 * **These are parsers over bytes.** Reading the file is the filesystem's job,
 * which is why nothing here takes a path.
 */
final readonly class Outcome {

	/**
	 * The buckets holding a mutant the engine counted towards the score.
	 *
	 * @var list<string>
	 */
	private const array KILLED = ["killed", "killedByStaticAnalysis", "errored", "syntaxErrors", "timeouted"];

	/**
	 * The buckets holding a mutant that proves nothing about the file it is in.
	 *
	 * `ignored` sits with the other two because an ignored mutant was never
	 * run: whatever the reason, nothing about it was established.
	 *
	 * @var list<string>
	 */
	private const array SURVIVED = ["escaped", "uncovered", "ignored"];

	/**
	 * Every absolute source path the log vouches for entirely, sorted.
	 *
	 * An absent section reads as empty, so a log from an engine that reports
	 * fewer of them is read rather than refused — and a log that is missing
	 * altogether vouches for nothing, which is the behaviour being replaced
	 * rather than a new failure.
	 *
	 * @param string $json The engine's JSON log.
	 * @return list<string>
	 */
	public static function provenIn(string $json): array {
		$log = Decoded::of($json, "the engine's log");
		$seen = [];
		$paths = [];

		foreach(array_diff(self::filesIn($log, self::KILLED), self::filesIn($log, self::SURVIVED)) as $path) {
			if(!isset($seen[$path])) {
				$seen[$path] = $path;
				$paths[] = $path;
			}
		}

		sort($paths);

		return $paths;
	}

	/**
	 * The files named by any of the given buckets, as a set.
	 *
	 * **A list rather than a set**, repeats and all. A set would want a value
	 * nothing reads, and a value nothing reads is a mutant nothing can kill.
	 *
	 * @param Decoded $log
	 * @param list<string> $buckets
	 * @return list<string>
	 */
	private static function filesIn(Decoded $log, array $buckets): array {
		$files = [];

		foreach($buckets as $bucket) {
			foreach($log->objects($bucket) as $mutant) {
				$files[] = $mutant->object("mutator")->string("originalFilePath");
			}
		}

		return $files;
	}
}
