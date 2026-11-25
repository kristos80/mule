<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Engine\Declaration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeclarationTest extends TestCase {

	/**
	 * The whole document, because what the engine is handed is the thing worth
	 * asserting: a key appearing that mule did not mean to write is as much of
	 * a change as one going missing.
	 *
	 * The engine resolves a configuration's relative paths against that file's
	 * own directory, and this one is written to the run's scratch space — so
	 * `src` would be looked for beside it, and the test runner's configuration
	 * along with it.
	 *
	 * @return void
	 */
	public function testRendersWhatTheEngineNeedsAndNothingElse(): void {
		self::assertSame([
			"source" => ["directories" => ["/project/src", "/project/lib"]],
			"phpUnit" => ["configDir" => "/project"],
			"minMsi" => 100,
			"minCoveredMsi" => 100,
		], $this->decoded($this->configuration(source: ["src", "lib"])));
	}

	/**
	 * The engine's schema forbids properties it does not define and defines
	 * fewer mutator names than the engine accepts, so naming them here failed
	 * whole runs. They are an argument now — see InfectionTest.
	 *
	 * @return void
	 */
	public function testSaysNothingAboutMutators(): void {
		self::assertArrayNotHasKey(
			"mutators",
			$this->decoded($this->configuration(mutators: ["@default", "@identical"])),
		);
	}

	/**
	 * Project-relative going in, relative to the source root coming out, which
	 * is the form the engine wants and not one a project should have to know.
	 *
	 * @return void
	 */
	public function testExcludesWhatTheProjectExcluded(): void {
		self::assertSame([
			"source" => [
				"directories" => ["/project/src"],
				"excludes" => ["External/Disk.php"],
			],
			"phpUnit" => ["configDir" => "/project"],
			"minMsi" => 100,
			"minCoveredMsi" => 100,
		], $this->decoded($this->configuration(exclude: ["src/External/Disk.php"])));
	}

	/**
	 * Stated here rather than on the command line, because it is what the gate
	 * *means* and does not vary between runs. Not to be read as mule's
	 * `min-coverage`, however alike the two look: this is a mutation score over
	 * the code the tests reach, and that is the proportion of lines they reach
	 * at all.
	 *
	 * @return void
	 */
	public function testStatesTheFullScoreTheGateInsistsOn(): void {
		$document = $this->decoded($this->configuration());

		self::assertSame(100, $document["minMsi"]);
		self::assertSame(100, $document["minCoveredMsi"]);
	}

	public function testAsksForTheTimeoutTheProjectWanted(): void {
		self::assertSame([
			"source" => ["directories" => ["/project/src"]],
			"phpUnit" => ["configDir" => "/project"],
			"minMsi" => 100,
			"minCoveredMsi" => 100,
			"timeout" => 15,
		], $this->decoded($this->configuration(timeout: 15)));
	}

	public function testWritesItselfAsBytesEndingInANewline(): void {
		$json = (new Declaration($this->configuration(), "/project"))->json();

		self::assertStringEndsWith("}\n", $json);
		self::assertStringContainsString("/project/src", $json, "slashes are left as they were written.");
	}

	public function testRefusesToRenderAnExclusionOutsideTheSource(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The excluded path docs/One.php in mule.json is not inside any of the source directories");

		(new Declaration($this->configuration(exclude: ["docs/One.php"]), "/project"))->json();
	}

	/**
	 * @param Configuration $configuration
	 * @return array<string, mixed>
	 */
	private function decoded(Configuration $configuration): array {
		$decoded = json_decode((new Declaration($configuration, "/project"))->json(), TRUE);

		self::assertIsArray($decoded);

		$document = [];

		foreach($decoded as $key => $value) {
			$document["{$key}"] = $value;
		}

		return $document;
	}

	/**
	 * @param list<string> $source
	 * @param list<string> $exclude
	 * @param list<string> $mutators
	 * @param int|null $timeout
	 * @return Configuration
	 */
	private function configuration(
		array $source = ["src"],
		array $exclude = [],
		array $mutators = ["@default"],
		?int $timeout = NULL,
	): Configuration {
		return new Configuration(
			[PHP_BINARY, "./vendor/bin/phpunit"],
			$source,
			$exclude,
			NULL,
			FALSE,
			$mutators,
			FALSE,
			NULL,
			$timeout,
			NULL,
		);
	}
}
