<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Coverage\Unreached;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Json\Decoded;

/**
 * What a run saw: a digest for every source file it proved, and for every test
 * file both its digest and the source files it was seen to run.
 *
 * Comparing two of these is the whole decision mule makes.
 *
 * **The test's reach is recorded rather than hashed into a single number**, as
 * an earlier version of this did. A digest over the covering files answers
 * "has anything about them changed" and nothing else; the list answers which
 * files share a test, which is what makes it possible to notice that changing
 * one file unproved another. It is also what a later run needs in order to ask
 * the test runner for a subset.
 */
final readonly class Snapshot {

	/**
	 * @param array<string, string> $verified Project-relative source path to its digest, sorted.
	 * @param array<string, Touched> $tests Project-relative test path to what it is and runs, sorted.
	 * @param array<string, Unproven> $unproven Source paths the tests do not reach all of, sorted.
	 * @param array<string, string> $watched Everything else the project autoloads, and its own config, sorted.
	 */
	private function __construct(
		private array $verified,
		private array $tests,
		private array $unproven,
		private array $watched,
	) {}

	/**
	 * @param array<string, string> $verified
	 * @param array<string, Touched> $tests
	 * @param array<string, Unproven> $unproven
	 * @param array<string, string> $watched
	 * @return self
	 */
	public static function of(array $verified, array $tests, array $unproven = [], array $watched = []): self {
		ksort($verified);
		ksort($tests);
		ksort($unproven);
		ksort($watched);

		return new self($verified, $tests, $unproven, $watched);
	}

	/**
	 * Everything this snapshot has a digest for, whatever section it sits in:
	 * what a later run has to re-hash to know that nothing has moved.
	 *
	 * @return array<string, string>
	 */
	public function digests(): array {
		$digests = $this->watched;

		foreach($this->verified as $path => $digest) {
			$digests[$path] = $digest;
		}

		foreach($this->unproven as $path => $entry) {
			$digests[$path] = $entry->digest;
		}

		foreach($this->tests as $path => $test) {
			$digests[$path] = $test->source;
		}

		return $digests;
	}

	/**
	 * The files the tests do not reach all of, and where they stop.
	 *
	 * @return array<string, list<\Kristos80\Mule\Coverage\Unreached>>
	 */
	public function gaps(): array {
		$gaps = [];

		foreach($this->unproven as $path => $entry) {
			$gaps[$path] = $entry->unreached;
		}

		return $gaps;
	}

	/**
	 * The digest each unproven file was measured against.
	 *
	 * @return array<string, string>
	 */
	public function unprovenDigests(): array {
		$digests = [];

		foreach($this->unproven as $path => $entry) {
			$digests[$path] = $entry->digest;
		}

		return $digests;
	}

	/**
	 * Everything the project autoloads that the report never mentioned, and
	 * mule's own configuration, with their digests.
	 *
	 * @return array<string, string>
	 */
	public function watched(): array {
		return $this->watched;
	}

	/**
	 * Every test recorded, with what it runs.
	 *
	 * @return array<string, Touched>
	 */
	public function tests(): array {
		return $this->tests;
	}

	/**
	 * A snapshot read back from the store.
	 *
	 * A store written by an older mule has neither section and reads as empty,
	 * so the next run proves everything once rather than refusing to start.
	 * Bytes that are not a JSON object at all still stop the run.
	 *
	 * @param string $json
	 * @return self
	 */
	public static function fromJson(string $json): self {
		$store = Decoded::of($json, "the mule store");
		$verified = [];
		$tests = [];
		$watched = [];

		foreach($store->object("verified")->entries() as $path => $entry) {
			$verified[$path] = $entry->string("digest");
		}

		foreach($store->object("tests")->entries() as $path => $entry) {
			$tests[$path] = new Touched($entry->string("source"), $entry->strings("touches"));
		}

		foreach($store->object("watched")->entries() as $path => $entry) {
			$watched[$path] = $entry->string("digest");
		}

		return self::of($verified, $tests, self::unprovenIn($store), $watched);
	}

	/**
	 * The unproven section, read back.
	 *
	 * @param Decoded $store
	 * @return array<string, Unproven>
	 */
	private static function unprovenIn(Decoded $store): array {
		$unproven = [];

		foreach($store->object("unproven")->entries() as $path => $entry) {
			$unreached = [];

			foreach($entry->objects("unreached") as $gap) {
				$unreached[] = new Unreached(
					$gap->string("name"),
					$gap->number("from"),
					$gap->number("to"),
					$gap->number("lines"),
				);
			}

			$unproven[$path] = new Unproven($entry->string("digest"), $unreached);
		}

		return $unproven;
	}

	/**
	 * @return list<string>
	 */
	public function paths(): array {
		return array_keys($this->verified);
	}

	/**
	 * @return int
	 */
	public function count(): int {
		return count($this->verified);
	}

	/**
	 * Of the paths given, the ones this snapshot holds a verdict for, in the
	 * order they were given.
	 *
	 * @param list<string> $paths
	 * @return list<string>
	 */
	public function knownOf(array $paths): array {
		$known = [];

		foreach($paths as $path) {
			if(isset($this->verified[$path])) {
				$known[] = $path;
			}
		}

		return $known;
	}

	/**
	 * The files in this snapshot whose verdict the stored one cannot vouch
	 * for: never recorded, recorded against different bytes, proven by a test
	 * that has changed, or proven by a test that runs something else that has.
	 *
	 * Direction matters. This snapshot is the universe — what the coverage
	 * report knows about — and the stored one only ever answers questions about
	 * it. A path the store holds and the report does not is a deleted or newly
	 * excluded file, and nothing needs mutating on its behalf.
	 *
	 * @param self $stored
	 * @return list<string>
	 */
	public function staleAgainst(self $stored): array {
		$moved = $this->moved($stored);
		$stale = [];

		foreach([...$this->changed($moved), ...$this->sharing($moved)] as $path) {
			$stale[$path] = $path;
		}

		ksort($stale);

		return array_keys($stale);
	}

	/**
	 * This snapshot with another's entries written over it.
	 *
	 * @param self $other
	 * @return self
	 */
	public function merged(self $other): self {
		return self::of(
			[...$this->verified, ...$other->verified],
			[...$this->tests, ...$other->tests],
			$other->unproven,
			$other->watched,
		);
	}

	/**
	 * Just the named files, and every test that runs one of them, which is
	 * what a narrowed run banks.
	 *
	 * @param list<string> $paths
	 * @return self
	 */
	public function only(array $paths): self {
		$verified = [];
		$wanted = [];

		foreach($paths as $path) {
			$verified[$path] = $this->verified[$path]
				?? throw new Failure("{$path} is not in this run's verdicts, so mule cannot bank it.");
			$wanted[] = $path;
		}

		return self::of($verified, $this->testsRunning(array_flip($wanted)), $this->unproven, $this->watched);
	}

	/**
	 * The snapshot as it is written: sorted, one entry per line, so that a
	 * rebase conflict in a committed store is mechanical to resolve.
	 *
	 * @return string
	 */
	public function toJson(): string {
		$verified = [];
		$tests = [];
		$unproven = [];
		$watched = [];

		foreach($this->verified as $path => $digest) {
			$verified[] = "\t\t" . self::encoded($path) . ": " . self::encoded(["digest" => $digest]);
		}

		foreach($this->tests as $path => $test) {
			$tests[] = "\t\t" . self::encoded($path) . ": "
				. self::encoded(["source" => $test->source, "touches" => $test->touches]);
		}

		foreach($this->unproven as $path => $entry) {
			$unproven[] = "\t\t" . self::encoded($path) . ": " . self::encoded($entry->toArray());
		}

		foreach($this->watched as $path => $digest) {
			$watched[] = "\t\t" . self::encoded($path) . ": " . self::encoded(["digest" => $digest]);
		}

		return "{\n\t\"verified\": {" . self::block($verified)
			. "},\n\t\"unproven\": {" . self::block($unproven)
			. "},\n\t\"tests\": {" . self::block($tests)
			. "},\n\t\"watched\": {" . self::block($watched) . "}\n}\n";
	}

	/**
	 * Every path whose bytes differ from what was stored, or that was never
	 * stored at all, as a set.
	 *
	 * @param self $stored
	 * @return array<string, int> A set: only the keys mean anything.
	 */
	private function moved(self $stored): array {
		$moved = [];

		foreach($this->verified as $path => $digest) {
			if(($stored->verified[$path] ?? NULL) !== $digest) {
				$moved[] = $path;
			}
		}

		foreach($this->tests as $path => $test) {
			if(($stored->tests[$path]->source ?? NULL) !== $test->source) {
				$moved[] = $path;
			}
		}

		return array_flip($moved);
	}

	/**
	 * The proven files among those that moved.
	 *
	 * @param array<string, int> $moved
	 * @return list<string>
	 */
	private function changed(array $moved): array {
		$changed = [];

		foreach($this->verified as $path => $digest) {
			if(isset($moved[$path])) {
				$changed[] = $path;
			}
		}

		return $changed;
	}

	/**
	 * The proven files whose tests moved, or run something that did.
	 *
	 * @param array<string, int> $moved
	 * @return list<string>
	 */
	private function sharing(array $moved): array {
		$sharing = [];

		foreach($this->tests as $path => $test) {
			if(isset($moved[$path]) || $test->runsAnyOf($moved)) {
				foreach($this->knownOf($test->touches) as $touched) {
					$sharing[] = $touched;
				}
			}
		}

		return $sharing;
	}

	/**
	 * Every test that runs one of the paths named.
	 *
	 * @param array<string, int> $wanted
	 * @return array<string, Touched>
	 */
	private function testsRunning(array $wanted): array {
		$running = [];

		foreach($this->tests as $path => $test) {
			if($test->runsAnyOf($wanted)) {
				$running[$path] = $test;
			}
		}

		return $running;
	}

	/**
	 * One section's lines, or nothing between the braces where it has none.
	 *
	 * @param list<string> $lines
	 * @return string
	 */
	private static function block(array $lines): string {
		return $lines === [] ? "" : "\n" . implode(",\n", $lines) . "\n\t";
	}

	/**
	 * One value as the store spells it: slashes as they were written, because
	 * a store full of `src\/Cache` is for a machine to read and this one is for
	 * a person resolving a conflict in it.
	 *
	 * @param string|array<string, mixed> $value
	 * @return string
	 */
	private static function encoded(string|array $value): string {
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
