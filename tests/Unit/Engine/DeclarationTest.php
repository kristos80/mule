<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Engine;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Engine\Declaration;
use PHPUnit\Framework\TestCase;
use function json_decode;
use const JSON_THROW_ON_ERROR;

final class DeclarationTest extends TestCase {

	/**
	 * @param array<string, mixed> $settings
	 * @return Declaration
	 */
	private function declaration(array $settings = []): Declaration {
		return new Declaration(
			Configuration::of($settings),
			".mule/cache/outcome.json",
			".mule/cache/tmp",
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<array-key, mixed>
	 */
	private function decoded(array $settings = []): array {
		$decoded = json_decode($this->declaration($settings)->rendered(), TRUE, flags: JSON_THROW_ON_ERROR);

		self::assertIsArray($decoded);

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<array-key, mixed>
	 */
	private function source(array $settings = []): array {
		$source = $this->decoded($settings)["source"];

		self::assertIsArray($source);

		return $source;
	}

	public function testItNamesTheSourceDirectory(): void {
		self::assertSame(["directories" => ["app"]], $this->source(["source" => "app"]));
	}

	public function testItCarriesTheGate(): void {
		self::assertSame(
			["@default" => TRUE, "@identical" => TRUE],
			$this->decoded(["mutators" => ["@identical" => TRUE, "@default" => TRUE]])["mutators"],
		);
	}

	/**
	 * There is no command-line flag for the JSON log, so the configuration has to
	 * carry it — which is the other half of why mule renders the file itself.
	 *
	 * @return void
	 */
	public function testItNamesWhereTheVerdictsGo(): void {
		self::assertSame(["json" => ".mule/cache/outcome.json"], $this->decoded()["logs"]);
	}

	public function testItChoosesTheEnginesScratchDirectory(): void {
		self::assertSame(".mule/cache/tmp", $this->decoded()["tmpDir"]);
	}

	public function testItNamesTheTestFramework(): void {
		self::assertSame("phpunit", $this->decoded()["testFramework"]);
	}

	/**
	 * By M15 a static analysis tool's only effect on a mule run is to turn test
	 * gaps into apparent successes, so there is never one — and "never" is worth
	 * asserting rather than leaving to the absence of a line.
	 *
	 * @return void
	 */
	public function testThereIsNoStaticAnalysisTool(): void {
		self::assertArrayNotHasKey("staticAnalysisTool", $this->decoded());
	}

	/**
	 * Infection's schema declares `minItems: 1` on `source.excludes`, so an empty
	 * array is a configuration it refuses outright.
	 *
	 * @return void
	 */
	public function testAnEmptyExcludesIsOmittedRatherThanWritten(): void {
		self::assertArrayNotHasKey("excludes", $this->source());
	}

	/**
	 * The engine wants them relative to the source directory; everything else mule
	 * does spells them relative to the project.
	 *
	 * @return void
	 */
	public function testExcludesAreSpelledTheWayTheEngineWantsThem(): void {
		self::assertSame(
			["directories" => ["src"], "excludes" => ["External/PhpFilesystem.php", "Legacy"]],
			$this->source(["exclude" => ["src/Legacy", "src/External/PhpFilesystem.php"]]),
		);
	}

	/**
	 * An excluded path outside the source directory is nothing to the engine: it
	 * would never have mutated it in the first place.
	 *
	 * @return void
	 */
	public function testAnExcludeOutsideTheSourceDirectoryIsNotPassedOn(): void {
		self::assertArrayNotHasKey("excludes", $this->source(["exclude" => ["tests/Helper.php"]]));
	}

	/**
	 * It is digested into the toolchain, so bytes that move for no reason
	 * invalidate the whole store.
	 *
	 * @return void
	 */
	public function testTheSameConfigurationRendersTheSameBytes(): void {
		self::assertSame($this->declaration()->rendered(), $this->declaration()->rendered());
	}

	public function testItIsReadableByAPerson(): void {
		self::assertStringContainsString("\n    \"mutators\"", $this->declaration()->rendered());
	}

	/**
	 * Escaped slashes would make every path in it unreadable for no gain, and the
	 * file is one somebody may well open.
	 *
	 * @return void
	 */
	public function testSlashesAreLeftAlone(): void {
		self::assertStringContainsString('".mule/cache/tmp"', $this->declaration()->rendered());
	}

	public function testItEndsWithANewline(): void {
		self::assertStringEndsWith("}\n", $this->declaration()->rendered());
	}

	/**
	 * Infection resolves every path in a configuration relative to that file's own
	 * directory (M11), so a declaration anywhere but the project root would need
	 * `../` in front of `source`.
	 *
	 * @return void
	 */
	public function testItIsWrittenAtTheProjectRoot(): void {
		self::assertSame("mule.infection.json", Declaration::FILE);
	}
}
