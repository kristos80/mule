<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

/**
 * Who covers what, and what that reaches.
 *
 * **Taken from the capture, not from the rendered report.** The report is an
 * output; the capture is the thing it was rendered from, and it already holds the
 * line data and the test-id table. Parsing six hundred XML documents back to
 * learn what mule had in hand a moment earlier would be a second answer to the
 * same question, and a second answer is a second thing to get wrong.
 *
 * **The paths are the capture's**, which means relative to its base path (M17) —
 * `Controller/Thing.php` rather than `src/Controller/Thing.php`. Translating them
 * into the store's spelling is the caller's, because only the caller knows which
 * root they hang from.
 */
final readonly class Attribution {

	/**
	 * @param array<string, list<non-empty-string>> $tests Source file to the test classes covering it.
	 * @param array<string, list<string>> $executed Test class to the source files it ran.
	 */
	private function __construct(
		private array $tests,
		private array $executed,
	) {}

	/**
	 * @param Capture $capture
	 * @return self
	 */
	public static function of(Capture $capture): self {
		$ids = $capture->testIds();
		$tests = [];
		$executed = [];

		foreach($capture->coverage->lineCoverage() as $file => $lines) {
			foreach($lines as $indices) {
				if(!is_array($indices)) {
					continue;
				}

				foreach(array_keys($indices) as $index) {
					$class = Capture::classOf($ids[$index]);
					// Appended rather than used as a set: a map whose values are
					// never read is a map whose values cannot be got wrong, and
					// the gate has said so three times now.
					$tests[$file][] = $class;
					$executed[$class][] = $file;
				}
			}
		}

		return new self(self::canonical($tests), self::canonical($executed));
	}

	/**
	 * Every source file the report attributes coverage to.
	 *
	 * @return list<string>
	 */
	public function files(): array {
		return array_keys($this->tests);
	}

	/**
	 * The test classes covering one file.
	 *
	 * @param string $file
	 * @return list<non-empty-string>
	 */
	public function testsFor(string $file): array {
		return $this->tests[$file] ?? [];
	}

	/**
	 * **Everything a verdict for this file rests on besides itself.**
	 *
	 * The union of what every covering test executed — which is wider than the
	 * files this one references, and deliberately so. A test class covering both
	 * `Gate.php` and `Decoded.php` makes `Gate.php` a dependency of
	 * `Decoded.php`'s verdict, because that test's behaviour depends on it. The
	 * edge runs through the shared test, not through the source. See
	 * `docs/keying.md` §2.
	 *
	 * @param string $file
	 * @return list<string>
	 */
	public function depsFor(string $file): array {
		$deps = [];

		foreach($this->testsFor($file) as $class) {
			foreach($this->executed[$class] ?? [] as $executed) {
				$deps[] = $executed;
			}
		}

		return self::sorted($deps);
	}

	/**
	 * **Canonical in both directions.** The key is taken over these lists, and
	 * the order a report happened to list coverage in is not a property of the
	 * project.
	 *
	 * @template T of string
	 * @param array<string, list<T>> $sets
	 * @return array<string, list<T>>
	 */
	private static function canonical(array $sets): array {
		$canonical = [];

		foreach($sets as $key => $values) {
			$canonical[$key] = self::sorted($values);
		}

		// **No outer sort, and that is a dependency rather than an omission.**
		// `ProcessedCodeCoverageData::setLineCoverage()` sorts its own keys, so
		// `lineCoverage()` arrives in canonical file order and the file-keyed map
		// is already sorted. The class-keyed one is never iterated — only looked
		// up — so its order is unobservable. `testEverythingIsCanonicallyOrdered`
		// feeds unsorted input and is the tripwire if that ever stops being true.
		return $canonical;
	}

	/**
	 * @template T of string
	 * @param list<T> $values
	 * @return list<T>
	 */
	private static function sorted(array $values): array {
		$unique = array_unique($values);
		sort($unique);

		return $unique;
	}
}
