<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Kristos80\Mule\Failure;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * One `--coverage-php` payload, validated.
 *
 * **This is the only place a coverage payload stops being `mixed`.** mule reads
 * and writes `php-code-coverage`'s own serialized shape rather than a public
 * API, so the one thing standing between a pinned dependency and silently wrong
 * shards is what this class refuses — see `docs/keying.md` M2. Nothing
 * downstream re-checks.
 *
 * **Every refusal carries its own message.** Not for the reader's benefit: a
 * guard that falls through to a neighbour's message is a guard no test can tell
 * apart from its neighbour, and therefore a mutant that survives for ever.
 */
final readonly class Capture {

	/**
	 * The only serialization format mule knows how to split and merge.
	 */
	public const int FORMAT = 4;

	/**
	 * @param ProcessedCodeCoverageData $coverage Line data, keyed by test index.
	 * @param array<non-empty-string, array{size: string, status: string, time: float}> $testResults Keyed by test id.
	 * @param string $basePath The common root the report's paths are relative to.
	 * @param array<array-key, mixed> $buildInformation Carried through untouched.
	 */
	private function __construct(
		public ProcessedCodeCoverageData $coverage,
		public array $testResults,
		public string $basePath,
		public array $buildInformation,
	) {}

	/**
	 * Reads a payload as it comes off disk, or out of another Capture.
	 *
	 * @param mixed $payload Whatever `require`ing a `.cov` file returned.
	 * @return self
	 */
	public static function of(mixed $payload): self {
		$payload = self::array($payload, "A coverage payload");
		$coverage = self::at($payload, "codeCoverage");

		if(!$coverage instanceof ProcessedCodeCoverageData) {
			throw new Failure("A coverage payload's \"codeCoverage\" is not a ProcessedCodeCoverageData.");
		}

		$buildInformation = self::array(self::at($payload, "buildInformation"), "A coverage payload's build information");
		$basePath = self::at($payload, "basePath");

		if(!is_string($basePath)) {
			throw new Failure("A coverage payload's \"basePath\" is not a string.");
		}

		self::assertFormat($buildInformation);

		return new self(
			$coverage,
			TestResults::of(self::array(self::at($payload, "testResults"), "A coverage payload's test results"), "A coverage payload's test results"),
			$basePath,
			$buildInformation,
		);
	}

	/**
	 * This capture with different coverage, keeping the build information so a
	 * shard still declares the format it was cut from.
	 *
	 * @param ProcessedCodeCoverageData $coverage
	 * @param array<non-empty-string, array{size: string, status: string, time: float}> $testResults
	 * @param string $basePath
	 * @return self
	 */
	public function with(ProcessedCodeCoverageData $coverage, array $testResults, string $basePath): self {
		return new self($coverage, $testResults, $basePath, $this->buildInformation);
	}

	/**
	 * The payload shape `php-code-coverage` reads back.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return [
			"buildInformation" => $this->buildInformation,
			"basePath" => $this->basePath,
			"codeCoverage" => $this->coverage,
			"testResults" => $this->testResults,
		];
	}

	/**
	 * Every test id this capture carries, in the index order the line data
	 * refers to them by.
	 *
	 * **The line data holds indices into this list, not test names.** Reading
	 * the values of a line's array instead of its keys yields a list of `1`s
	 * that looks like data and silently produces empty shards — see M3.
	 *
	 * @return array<int<0, max>, non-empty-string>
	 */
	public function testIds(): array {
		return $this->coverage->testIds();
	}

	/**
	 * The test classes this capture attributes coverage to.
	 *
	 * **Split the way Infection splits it**, which is on the first `:` rather
	 * than on `::` — `JUnitTestExecutionInfoAdder::createCompleteTestLocation()`
	 * does `explode(":", $method, 2)[0]`, and the timings this is checked against
	 * have to be keyed by whatever *that* produces. Deriving the class any other
	 * way would pass G4 and still leave Infection unable to find the class it
	 * looked for.
	 *
	 * Infection's limit of 2 is not copied, because only `[0]` is ever read and
	 * the limit cannot change it — `explode(":", "A::b", 2)[0]` and
	 * `explode(":", "A::b", 3)[0]` are both `"A"`. The gate made that argument
	 * first, by surviving the change.
	 *
	 * @return list<non-empty-string>
	 */
	public function testClasses(): array {
		$classes = [];

		foreach($this->testIds() as $id) {
			$classes[] = self::classOf($id);
		}

		return array_values(array_unique($classes));
	}

	/**
	 * The class part of a test id.
	 *
	 * The same rule the timings are keyed by:
	 * whatever this produces, so two spellings of it would be two answers.
	 *
	 * **A guard rather than an assumption**: `explode(":", ":x")[0]` is `""`, so
	 * an id beginning with a colon would yield a class name no JUnit lookup can
	 * match — and G4 would pass while Infection went on to throw `TestNotFound`.
	 *
	 * @param non-empty-string $id
	 * @return non-empty-string
	 */
	private static function classOf(string $id): string {
		$class = explode(":", $id)[0];

		if($class === "") {
			throw new Failure("A coverage payload attributes coverage to \"{$id}\", which names no test class.");
		}

		return $class;
	}

	/**
	 * One required key's value.
	 *
	 * @param array<array-key, mixed> $payload
	 * @param string $key
	 * @return mixed
	 */
	private static function at(array $payload, string $key): mixed {
		if(!array_key_exists($key, $payload)) {
			throw new Failure("A coverage payload is missing its \"{$key}\" key.");
		}

		return $payload[$key];
	}

	/**
	 * **`array-key`, not `string`.** `is_array()` proves nothing about the keys,
	 * and claiming otherwise would be a type standing in for a check. Whoever
	 * needs string keys says so and guards for it — `TestResults` does.
	 *
	 * @param mixed $value
	 * @param string $what Named in the refusal, so each caller's message differs.
	 * @return array<array-key, mixed>
	 */
	private static function array(mixed $value, string $what): array {
		if(!is_array($value)) {
			throw new Failure("{$what} must be an array, " . get_debug_type($value) . " given.");
		}

		return $value;
	}

	/**
	 * **Two refusals, because there are two things that can be wrong.** A
	 * payload that declares no format at all is a different fault from one
	 * declaring a format mule cannot read, and giving them one message between
	 * them is how the guard between them becomes unkillable.
	 *
	 * @param array<array-key, mixed> $buildInformation
	 * @return void
	 */
	private static function assertFormat(array $buildInformation): void {
		$declared = $buildInformation["phpCodeCoverage"] ?? NULL;

		if(!is_array($declared)) {
			throw new Failure("A coverage payload's build information does not declare a serialization format.");
		}

		$format = $declared["serializationFormat"] ?? NULL;

		if($format !== self::FORMAT) {
			throw new Failure(
				"mule reads php-code-coverage serialization format " . self::FORMAT
				. ", this payload declares " . var_export($format, TRUE)
				. ". Pinning phpunit/php-code-coverage is what keeps these in step.",
			);
		}
	}
}
