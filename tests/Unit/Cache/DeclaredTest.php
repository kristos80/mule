<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Declared;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\CoveringProcesses;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\MappedClasses;
use PHPUnit\Framework\TestCase;

/**
 * What a verdict would mean if it were proven now, independently of any store.
 */
final class DeclaredTest extends TestCase {

	private const string COMPOSER = '{"autoload": {"psr-4": {"Acme\\\\": "src"}}}';

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())->given("/project/composer.json", self::COMPOSER);
	}

	public function testDigestsTheGateAsOneAnswer(): void {
		self::assertSame(["gate"], array_keys($this->declared()->digests()));
	}

	public function testTheSameGateDigestsTheSameWayTwice(): void {
		self::assertSame($this->declared()->digests(), $this->declared()->digests());
	}

	/**
	 * The keys that decide what a mutant proves.
	 *
	 * @return void
	 */
	public function testADifferentGateDigestsDifferently(): void {
		$before = $this->declared()->digests();
		$this->givenMuleJson('{"mutators": {"default": true}}');

		self::assertNotSame($before, $this->declared()->digests());
	}

	public function testAnExclusionChangesTheGate(): void {
		$before = $this->declared()->digests();
		$this->givenMuleJson('{"exclude": ["src/Generated"]}');

		self::assertNotSame($before, $this->declared()->digests());
	}

	/**
	 * Which tests coverage is worked out from decides which tests a verdict
	 * rests on.
	 *
	 * @return void
	 */
	public function testTheSuiteCoverageIsWorkedOutFromChangesTheGate(): void {
		$before = $this->declared()->digests();
		$this->givenMuleJson('{"tests": "unit"}');

		self::assertNotSame($before, $this->declared()->digests());
	}

	/**
	 * Mutating code no test reaches changes which mutants exist.
	 *
	 * @return void
	 */
	public function testMutatingUncoveredCodeChangesTheGate(): void {
		$before = $this->declared()->digests();
		$this->givenMuleJson('{"mutate-uncovered": true}');

		self::assertNotSame($before, $this->declared()->digests());
	}

	/**
	 * A mutant the clock killed counts as dead, so how long the clock runs
	 * decides what a verdict asserts.
	 *
	 * @return void
	 */
	public function testTheTimeoutChangesTheGate(): void {
		$before = $this->declared()->digests();
		$this->givenMuleJson('{"timeout": 30}');

		self::assertNotSame($before, $this->declared()->digests());
	}

	/**
	 * **The reason this hashes the decision rather than the file.** Neither of
	 * these changes what a mutant proves: one is how fast the engine goes, the
	 * other an assertion about lines. Hashing `mule.json` itself threw away
	 * every verdict in the store for tuning a run's speed.
	 *
	 * @return void
	 */
	public function testSpeedAndCoverageThresholdsChangeNothing(): void {
		$this->givenMuleJson('{"mutators": {"default": true}}');
		$before = $this->declared()->digests();
		$this->givenMuleJson('{"mutators": {"default": true}, "threads": "max", "min-coverage": 80, "narrow": true}');

		self::assertSame($before, $this->declared()->digests());
	}

	/**
	 * Reformatting is not redefining. The file's bytes move and the gate does
	 * not.
	 *
	 * @return void
	 */
	public function testReorderingAndReformattingChangeNothing(): void {
		$this->givenMuleJson('{"exclude": ["src/A.php"], "mutators": {"default": true}}');
		$before = $this->declared()->digests();
		$this->givenMuleJson("{\n\t\"mutators\":   {\"default\": true},\n\t\"exclude\": [\"src/A.php\"]\n}");

		self::assertSame($before, $this->declared()->digests());
	}

	/**
	 * `composer require` must not throw away a store. Measured on a real
	 * project, where removing two unused dev dependencies cost 610 verdicts.
	 *
	 * @return void
	 */
	public function testAddingADependencyChangesNothing(): void {
		$before = $this->declared()->digests();
		$this->filesystem->given(
			"/project/composer.json",
			'{"autoload": {"psr-4": {"Acme\\\\": "src"}}, "require-dev": {"acme/thing": "^1.0"}}',
		);

		self::assertSame($before, $this->declared()->digests());
	}

	/**
	 * Where the source lives is the one thing in composer.json that changes a
	 * verdict, and it reaches the gate already parsed.
	 *
	 * @return void
	 */
	public function testMovingTheSourceChangesTheGate(): void {
		$before = $this->declared()->digests();
		$this->filesystem->given("/project/composer.json", '{"autoload": {"psr-4": {"Acme\\\\": "app"}}}');

		self::assertNotSame($before, $this->declared()->digests());
	}

	public function testDeclaresTheEngineThatWouldProveAVerdict(): void {
		self::assertSame(
			["infection/infection" => "0.32.7"],
			$this->declared(["infection/infection" => "0.32.7"])->engine(),
		);
	}

	/**
	 * A package installed from a branch has no pretty version, and refusing to
	 * run would be worse than recording that the question had no answer.
	 *
	 * @return void
	 */
	public function testDeclaresNoEngineWhereThereIsNoVersionToRead(): void {
		self::assertSame([], $this->declared()->engine());
	}

	/**
	 * @param string $contents
	 * @return void
	 */
	private function givenMuleJson(string $contents): void {
		$this->filesystem->given("/project/mule.json", $contents);
	}

	/**
	 * @param array<string, string> $versions
	 * @return Declared
	 */
	private function declared(array $versions = []): Declared {
		$project = Project::containing("/project", $this->filesystem);
		$configuration = Configuration::load($project, $this->filesystem, new CoveringProcesses($this->filesystem));

		return new Declared($configuration, new MappedClasses([], [], $versions));
	}
}
