<?php
declare(strict_types=1);

namespace Kristos80\Mule\Config;

use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Json\Decoded;
use Kristos80\Mule\Project;

/**
 * What mule needs to know about a project, inferred where it can be and
 * declared where it cannot.
 *
 * **Inference over declaration.** A project has already said what runs its
 * tests and what mutates them; asking it to repeat that in a second file is how
 * two sources of truth start disagreeing. The universe of source files comes
 * from the coverage report, the covering tests from the project's autoloader,
 * and both binaries from whichever one is installed. `mule.json` exists for the
 * two things none of that can answer: a coverage command behind a wrapper such
 * as Herd's, and the engine's own options.
 *
 * **The store's path is not configurable.** There is one of it, it is committed
 * with the project, and a path to it would buy a traversal check, two error
 * messages and the tests to go with them.
 */
final readonly class Configuration {

	public const string COVERAGE_PLACEHOLDER = "{coverage-xml}";

	public const string JUNIT_PLACEHOLDER = "{junit}";

	private const string FILE = "mule.json";

	/**
	 * @param list<string> $coverage The coverage command, with both placeholders.
	 * @param string $binary The engine's binary.
	 * @param list<string> $options Options passed to the engine as given.
	 */
	public function __construct(
		public array $coverage,
		public string $binary,
		public array $options,
	) {}

	/**
	 * A project's configuration: `mule.json` where there is one, inference
	 * everywhere else.
	 *
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @return self
	 */
	public static function load(Project $project, FilesystemInterface $filesystem): self {
		$file = $project->absolute(self::FILE);
		$declared = Decoded::of($filesystem->exists($file) ? $filesystem->read($file) : "{}", self::FILE);

		return new self(
			self::coverageOf($declared, $project, $filesystem),
			$declared->stringOr("binary", self::engineOf($project, $filesystem)),
			$declared->strings("options"),
		);
	}

	/**
	 * The coverage command, which must write both a `coverage-xml` directory
	 * and a JUnit log: an engine wants the first to decide which tests cover
	 * which line, and the second to time them.
	 *
	 * @param Decoded $declared
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @return list<string>
	 */
	private static function coverageOf(Decoded $declared, Project $project, FilesystemInterface $filesystem): array {
		$coverage = $declared->strings("coverage");
		$file = self::FILE;

		if($coverage === []) {
			return [
				self::runnerOf($project, $filesystem),
				"--coverage-xml=" . self::COVERAGE_PLACEHOLDER,
				"--log-junit=" . self::JUNIT_PLACEHOLDER,
			];
		}

		foreach([self::COVERAGE_PLACEHOLDER, self::JUNIT_PLACEHOLDER] as $placeholder) {
			if(!str_contains(implode(" ", $coverage), $placeholder)) {
				throw new Failure("The coverage command in {$file} must pass {$placeholder} to the test runner, so mule can tell it where to write.");
			}
		}

		return $coverage;
	}

	/**
	 * Whichever test runner the project installed.
	 *
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @return string
	 */
	private static function runnerOf(Project $project, FilesystemInterface $filesystem): string {
		return self::installed("pest", $project, $filesystem) ? "./vendor/bin/pest" : "./vendor/bin/phpunit";
	}

	/**
	 * Infection where the project has it, and otherwise the command on the
	 * path, which is where a project that cannot share a `composer.json` with
	 * it keeps it.
	 *
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @return string
	 */
	private static function engineOf(Project $project, FilesystemInterface $filesystem): string {
		return self::installed("infection", $project, $filesystem) ? "./vendor/bin/infection" : "infection";
	}

	/**
	 * @param string $binary
	 * @param Project $project
	 * @param FilesystemInterface $filesystem
	 * @return bool
	 */
	private static function installed(string $binary, Project $project, FilesystemInterface $filesystem): bool {
		return $filesystem->exists($project->absolute("vendor/bin/{$binary}"));
	}
}
