<?php
declare(strict_types=1);

namespace Kristos80\Mule\Config;

use Kristos80\Mule\Failure;
use function implode;

/**
 * `mule.json`, and the whole vocabulary a consumer has.
 *
 * **It names no PHPUnit key and no Infection key.** The engine's configuration
 * is rendered by mule, which is what lets it go into the cache key — and why a
 * project-level `infection.json5` is refused rather than ignored (§4). What is
 * here is the gate in mule's own words.
 *
 * **Nothing machine-local, either.** The store is committed, so a configuration
 * carrying an absolute path is a store no other machine can use. Enabling a
 * coverage driver is done by wrapping the command, never written down.
 */
final readonly class Configuration {

	/**
	 * Everything a consumer may write.
	 */
	private const array KEYS = [
		"source",
		"tests",
		"support",
		"exclude",
		"mutators",
		"min-coverage",
		"threads",
	];

	/**
	 * The modes `docs/keying.md` specifies and v1 does not implement.
	 *
	 * **Refused rather than ignored**, which is the whole point of naming them: a
	 * key that is read past is a key somebody sets, watches a green build, and
	 * believes did something.
	 */
	private const array DEFERRED = ["narrow", "signature"];

	/**
	 * @param string $source The source root, project-relative.
	 * @param string $tests The unit-test root, project-relative.
	 * @param list<string> $support Other directories whose bytes can change a verdict.
	 * @param list<string> $exclude Files neither mutated nor required to be proven.
	 * @param array<string, bool> $mutators The gate, in Infection's mutator vocabulary.
	 * @param int $minCoverage The line-coverage floor.
	 * @param string $threads What to hand the engine's `--threads`.
	 */
	private function __construct(
		public string $source,
		public string $tests,
		public array $support,
		public array $exclude,
		public array $mutators,
		public int $minCoverage,
		public string $threads,
	) {}

	/**
	 * @param mixed $decoded
	 * @return self
	 */
	public static function of(mixed $decoded): self {
		if(!is_array($decoded)) {
			throw new Failure("mule.json must be an object, " . get_debug_type($decoded) . " given.");
		}

		self::assertNothingUnknown($decoded);

		$configuration = new self(
			self::directory($decoded, "source", "src"),
			self::directory($decoded, "tests", "tests"),
			self::paths($decoded["support"] ?? [], "support"),
			self::paths($decoded["exclude"] ?? [], "exclude"),
			self::mutators($decoded["mutators"] ?? ["@default" => TRUE]),
			self::count($decoded["min-coverage"] ?? 100, "min-coverage"),
			self::threads($decoded["threads"] ?? "max"),
		);

		self::assertNothingMachineLocal($configuration);

		return $configuration;
	}

	/**
	 * Every directory a consumer named, which is every directory that has to be
	 * inside the project.
	 *
	 * @return list<string>
	 */
	private function roots(): array {
		return [$this->source, $this->tests, ...$this->support];
	}

	/**
	 * Whether a project-relative path is one mule neither mutates nor requires to
	 * be proven.
	 *
	 * **A named directory excludes everything under it**, because "this part is
	 * not mine to gate" is the usual thing somebody means, and spelling out every
	 * file in it is a list that goes stale the next time one is added.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function excludes(string $path): bool {
		foreach($this->exclude as $excluded) {
			if($path === $excluded || str_starts_with($path, "{$excluded}/")) {
				return TRUE;
			}
		}

		return FALSE;
	}

	/**
	 * @param array<array-key, mixed> $decoded
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	private static function directory(array $decoded, string $key, string $default): string {
		$value = $decoded[$key] ?? $default;

		if(!is_string($value) || $value === "") {
			throw new Failure("mule.json gives \"{$key}\" as something other than a directory.");
		}

		return rtrim($value, "/");
	}

	/**
	 * @param mixed $value
	 * @param string $key
	 * @return list<string>
	 */
	private static function paths(mixed $value, string $key): array {
		if(!is_array($value) || !array_is_list($value)) {
			throw new Failure("mule.json gives \"{$key}\" as something other than a list of paths.");
		}

		$paths = [];

		foreach($value as $path) {
			if(!is_string($path) || $path === "") {
				throw new Failure("mule.json has a \"{$key}\" entry that is not a path.");
			}

			$paths[] = $path;
		}

		sort($paths);

		return $paths;
	}

	/**
	 * **The mutator set decides which mutants exist**, so it is in the cache key
	 * and has to be canonical: the same gate written in a different order is the
	 * same gate.
	 *
	 * @param mixed $value
	 * @return array<string, bool>
	 */
	private static function mutators(mixed $value): array {
		if(!is_array($value) || $value === []) {
			throw new Failure("mule.json must name at least one mutator; without one there is no gate.");
		}

		$mutators = [];

		foreach($value as $name => $enabled) {
			if(!is_string($name) || $name === "" || !is_bool($enabled)) {
				throw new Failure("mule.json gives a mutator that is not a name and a yes or no.");
			}

			$mutators[$name] = $enabled;
		}

		ksort($mutators);

		return $mutators;
	}

	/**
	 * @param mixed $value
	 * @param string $key
	 * @return int
	 */
	private static function count(mixed $value, string $key): int {
		if(!is_int($value) || $value < 0 || $value > 100) {
			throw new Failure("mule.json gives \"{$key}\" as something other than a percentage.");
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function threads(mixed $value): string {
		if($value === "max") {
			return "max";
		}

		if(is_int($value) && $value > 0) {
			return (string) $value;
		}

		throw new Failure("mule.json gives \"threads\" as something other than \"max\" or a count.");
	}

	/**
	 * **A key mule does not know fails rather than being read past.** A typo in
	 * `min-coverage` is a floor nobody set, and the only sign of it is a gate that
	 * quietly asks for less than somebody wrote down.
	 *
	 * @param array<array-key, mixed> $decoded
	 * @return void
	 */
	private static function assertNothingUnknown(array $decoded): void {
		foreach(array_keys($decoded) as $key) {
			if(in_array($key, self::DEFERRED, TRUE)) {
				throw new Failure(
					"mule.json sets \"{$key}\", which this version does not implement. It is specified"
					. " in docs/keying.md §6 and nothing has been built or measured against it, so it"
					. " is refused rather than ignored — a key that is read past is a key you would"
					. " believe did something.",
				);
			}

			if(!in_array($key, self::KEYS, TRUE)) {
				throw new Failure(
					"mule.json gives \"{$key}\", which mule does not know. The keys are "
					. implode(", ", self::KEYS) . ".",
				);
			}
		}
	}

	/**
	 * **An absolute path here is a store no other machine can use**, because the
	 * store is committed and every path in it is project-relative. A
	 * configuration that is not byte-identical everywhere is not a configuration
	 * that can be digested.
	 *
	 * @param self $configuration
	 * @return void
	 */
	private static function assertNothingMachineLocal(self $configuration): void {
		foreach([...$configuration->roots(), ...$configuration->exclude] as $path) {
			if(str_starts_with($path, "/") || str_contains($path, "..")) {
				throw new Failure(
					"mule.json names \"{$path}\", which is not inside the project. The store is"
					. " committed and every path in it is project-relative, so nothing here may"
					. " be machine-local.",
				);
			}
		}
	}
}
