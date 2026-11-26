<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Kristos80\Mule\Config\Configuration;
use function json_encode;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The configuration mule hands the engine.
 *
 * **Rendered, never a consumer's.** Infection resolves every path in its
 * configuration relative to that file's own directory rather than to the working
 * directory (M11), and there is no command-line flag for the JSON log — so the
 * file has to exist, inside the project, written by mule. Which is also what
 * lets it go into the cache key.
 *
 * And it is why a project-level `infection.json5` is refused rather than ignored:
 * every key that would defeat a guard can be set in one —
 * `ignoreMsiWithNoMutations` (G5), `staticAnalysisTool` (M15), `timeoutsAsEscaped`
 * (G8), `timeout` (M12), the MSI floors, `logs`, `tmpDir`, `mutators`, `source`.
 * Honouring a consumer's file defeats the guard; ignoring it defeats the person
 * who wrote it and watched a green build.
 *
 * **No static analysis tool, ever.** Its only effect on a mule run would be to
 * turn test gaps into apparent successes.
 */
final readonly class Declaration {

	/**
	 * Where it is written, project-relative.
	 *
	 * **At the project root rather than in the scratch directory**, because
	 * Infection resolves every path in a configuration relative to that file's own
	 * directory (M11) — so a declaration one level down would need `../` in front
	 * of `source`, and a consumer looking at the file would read a configuration
	 * that appears to name directories outside the project.
	 */
	public const string FILE = "mule.infection.json";

	/**
	 * @param Configuration $configuration
	 * @param string $log Where the engine should write its JSON verdicts.
	 * @param string $temporary The engine's scratch directory.
	 */
	public function __construct(
		private Configuration $configuration,
		private string $log,
		private string $temporary,
	) {}

	/**
	 * **Pretty-printed and sorted**, because it is digested into the toolchain
	 * and a configuration whose bytes move for no reason invalidates the whole
	 * store.
	 *
	 * @return string
	 */
	public function rendered(): string {
		return json_encode([
			"source" => $this->source(),
			"mutators" => $this->configuration->mutators,
			"logs" => ["json" => $this->log],
			"testFramework" => "phpunit",
			"tmpDir" => $this->temporary,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	/**
	 * **An empty `excludes` is omitted rather than written.** Infection's schema
	 * declares `minItems: 1` on it, so an empty array is a configuration it
	 * refuses outright — checked against the schema rather than remembered.
	 *
	 * @return array<string, mixed>
	 */
	private function source(): array {
		$excludes = $this->excludes();

		if($excludes === []) {
			return ["directories" => [$this->configuration->source]];
		}

		return ["directories" => [$this->configuration->source], "excludes" => $excludes];
	}

	/**
	 * The excluded files, spelled the way the engine wants them: relative to the
	 * source directory rather than to the project.
	 *
	 * @return list<string>
	 */
	private function excludes(): array {
		$prefix = "{$this->configuration->source}/";
		$excludes = [];

		foreach($this->configuration->exclude as $path) {
			if(str_starts_with($path, $prefix)) {
				$excludes[] = substr($path, strlen($prefix));
			}
		}

		return $excludes;
	}
}
