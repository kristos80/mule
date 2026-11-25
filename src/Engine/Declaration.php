<?php
declare(strict_types=1);

namespace Kristos80\Mule\Engine;

use Kristos80\Mule\Config\Configuration;

/**
 * The configuration file the engine is handed, rendered from mule's own.
 *
 * **The engine cannot be driven by flags alone.** It has no option for the
 * directories to mutate, and with no configuration file it tries to generate
 * one and gives up when nothing is there to answer it — so a project without an
 * engine config of its own could never be gated. Writing one per run removes
 * that requirement and takes the engine's whole vocabulary out of the project's
 * sight: `mule.json` names no tool, and this is the one place that knows what
 * the other end calls things.
 *
 * **Mule's own decisions are rendered last and cannot be contradicted**, because
 * nothing a project writes reaches here unparsed. There is no `minMsi` to
 * smuggle in, because `Configuration` has nowhere to put one.
 *
 * **Every path is absolute.** The engine resolves the relative ones in a
 * configuration file against that file's own directory, and this file lives in
 * the run's scratch directory rather than the project — so `src` would be
 * looked for beside it, and the test runner's configuration along with it.
 * Measured before it was understood: a generated config in `/tmp` sent the
 * engine looking for `phpunit.xml` in `/tmp`.
 *
 * **These are documents, not files.** Writing the bytes is the filesystem's
 * job, which is why nothing here takes a path.
 */
final readonly class Declaration {

	/**
	 * The only score a verdict can be banked from.
	 *
	 * Mule reads an exit code and never the engine's report, so below a hundred
	 * there is no per-file fact to be had: exit 0 would be as consistent with
	 * one file whole and another barely tested, and a narrowed run would be
	 * asking a different question from a whole one.
	 */
	private const int FULL_SCORE = 100;

	/**
	 * @param Configuration $configuration
	 * @param string $root The project's absolute root.
	 */
	public function __construct(
		private Configuration $configuration,
		private string $root,
	) {}

	/**
	 * The engine's configuration for this run, as the bytes to write.
	 *
	 * @return string
	 */
	public function json(): string {
		return json_encode($this->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	}

	/**
	 * **An empty `excludes` is omitted rather than written empty**, because the
	 * engine's own schema requires the key to hold at least one entry — and an
	 * empty list is the common case, since a project with nothing to leave out
	 * writes no `mule.json` at all.
	 *
	 * **`timeout` likewise.** Absent means the engine's own default, which is
	 * not a number mule should have an opinion about.
	 *
	 * @return array<string, mixed>
	 */
	private function document(): array {
		$document = [
			"source" => ["directories" => $this->directories()],
			"phpUnit" => ["configDir" => $this->root],
			"minMsi" => self::FULL_SCORE,
			"minCoveredMsi" => self::FULL_SCORE,
		];
		$excludes = $this->configuration->excludedWithinSource();

		if($excludes !== []) {
			$document["source"]["excludes"] = $excludes;
		}

		if($this->configuration->timeout !== NULL) {
			$document["timeout"] = $this->configuration->timeout;
		}

		return $document;
	}

	/**
	 * @return list<string>
	 */
	private function directories(): array {
		$directories = [];

		foreach($this->configuration->source as $source) {
			$directories[] = "{$this->root}/{$source}";
		}

		return $directories;
	}

}
