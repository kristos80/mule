<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Config;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\CoveringProcesses;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\WrappedProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigurationTest extends TestCase {

	private const string COMPOSER = '{"autoload": {"psr-4": {"Acme\\\\": "src"}}}';

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())->given("/project/composer.json", self::COMPOSER);
	}

	public function testInfersAPhpunitRunUnderTheVeryPhpMuleIsRunningUnder(): void {
		self::assertSame([PHP_BINARY, "./vendor/bin/phpunit"], $this->configuration()->runner);
	}

	/**
	 * A coverage driver is usually enabled by an ini rather than by a binary, so
	 * naming the interpreter is not enough: measured under such a wrapper, a
	 * child started as plain PHP_BINARY loads no ini and has no driver.
	 *
	 * @return void
	 */
	public function testHandsTheRunnerTheIniMuleWasStartedWith(): void {
		$configuration = Configuration::load(
			Project::containing("/project", $this->filesystem),
			$this->filesystem,
			new WrappedProcesses("/herd/debug.ini"),
		);

		self::assertSame(
			[PHP_BINARY, "-c", "/herd/debug.ini", "./vendor/bin/phpunit"],
			$configuration->runner,
		);
	}

	public function testInfersAPestRunForAProjectThatInstalledPest(): void {
		$this->filesystem->given("/project/vendor/bin/pest", "#!/usr/bin/env php");

		self::assertSame([PHP_BINARY, "./vendor/bin/pest"], $this->configuration()->runner);
	}

	public function testInfersWhatToMutateFromWhatTheProjectAutoloadsAsItsOwn(): void {
		self::assertSame(["src"], $this->configuration()->source);
	}

	public function testInfersEverySourceRootAndTrimsTheirSeparators(): void {
		$this->filesystem->given(
			"/project/composer.json",
			'{"autoload": {"psr-4": {"Acme\\\\": ["src/", "lib"], "Acme\\\\Extra\\\\": "extra"}}}',
		);

		self::assertSame(["src", "lib", "extra"], $this->configuration()->source);
	}

	/**
	 * The autoloader mule asks about classes answers for tests as well, which is
	 * right for knowing what files exist and wrong for knowing what to mutate.
	 *
	 * @return void
	 */
	public function testDoesNotInferTheTestRootsAsSomethingToMutate(): void {
		$this->filesystem->given(
			"/project/composer.json",
			'{"autoload": {"psr-4": {"Acme\\\\": "src"}}, "autoload-dev": {"psr-4": {"Acme\\\\Tests\\\\": "tests"}}}',
		);

		self::assertSame(["src"], $this->configuration()->source);
	}

	public function testPrefersADeclaredSourceOverWhatItWouldInfer(): void {
		$this->givenMuleJson('{"source": ["app", "domain"]}');

		self::assertSame(["app", "domain"], $this->configuration()->source);
	}

	public function testRejectsAProjectThatAutoloadsNothingItCouldMutate(): void {
		$this->filesystem->given("/project/composer.json", "{}");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("This project autoloads nothing under psr-4, so mule cannot tell what to mutate: name the directories as source in mule.json.");

		$this->configuration();
	}

	public function testGatesOnDefaultAndIdenticalWhenAProjectSaysNothing(): void {
		self::assertSame(["@default", "@identical"], $this->configuration()->mutators);
	}

	public function testTranslatesTheFamiliesAProjectAsksForIntoTheEnginesOwnNames(): void {
		$this->givenMuleJson('{"mutators": {"default": true, "conditional-boundary": true, "return-value": true}}');

		self::assertSame(
			["@default", "@conditional_boundary", "@return_value"],
			$this->configuration()->mutators,
		);
	}

	public function testLeavesOutAFamilyAProjectTurnedOff(): void {
		$this->givenMuleJson('{"mutators": {"default": true, "identical": false}}');

		self::assertSame(["@default"], $this->configuration()->mutators);
	}

	/**
	 * The one place the engine's vocabulary shows, and it is admitted because
	 * there is no mule word for it: the project mule was written for turns on
	 * `ArrayFind` and `ArrayFindKey` individually, the engine shipping no
	 * profile its schema accepts for them.
	 *
	 * @return void
	 */
	public function testPassesASingleMutatorThroughAsTheEngineSpellsIt(): void {
		$this->givenMuleJson('{"mutators": {"default": true, "ArrayFind": true, "ArrayFindKey": true}}');

		self::assertSame(
			["@default", "ArrayFind", "ArrayFindKey"],
			$this->configuration()->mutators,
		);
	}

	public function testRejectsAFamilyMuleDoesNotHave(): void {
		$this->givenMuleJson('{"mutators": {"wishful": true}}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The mutator family wishful in mule.json is not one mule has: arithmetic, boolean, cast, conditional-boundary, conditional-negotiation, default, equal, extensions, function-signature, identical, loop, nullify, number, operator, regex, removal, return-value, sort, unwrap.");

		$this->configuration();
	}

	public function testRejectsAFamilyThatIsNeitherOnNorOff(): void {
		$this->givenMuleJson('{"mutators": {"default": "yes"}}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The default in mule.json mutators must be true or false.");

		$this->configuration();
	}

	/**
	 * Mule owns this whole vocabulary, so a key it does not recognise is a
	 * mistake every time — a misspelling that would otherwise take effect as
	 * silence, or an attempt to reach the engine directly.
	 *
	 * @return void
	 */
	public function testRefusesAKeyItDoesNotHave(): void {
		$this->givenMuleJson('{"options": ["--min-msi=90"]}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The key options in mule.json is not one mule has: source, exclude, tests, narrow, mutators, mutate-uncovered, min-coverage, timeout, threads.");

		$this->configuration();
	}

	public function testReadsEverythingElseAProjectDeclares(): void {
		$this->givenMuleJson('{
			"exclude": ["src/Generated"],
			"tests": "unit",
			"narrow": true,
			"mutate-uncovered": true,
			"min-coverage": 80,
			"timeout": 15,
			"threads": "max"
		}');

		$configuration = $this->configuration();

		self::assertSame(["src/Generated"], $configuration->exclude);
		self::assertSame("unit", $configuration->tests);
		self::assertTrue($configuration->narrow);
		self::assertTrue($configuration->mutateUncovered);
		self::assertSame(80, $configuration->minCoverage);
		self::assertSame(15, $configuration->timeout);
		self::assertSame("max", $configuration->threads);
	}

	/**
	 * Narrowing a test run is sound only where tests do not depend on each
	 * other having run, which is not something mule may assume on a project's
	 * behalf.
	 *
	 * @return void
	 */
	public function testWillNotNarrowACoverageRunUntilAProjectSaysItMay(): void {
		self::assertFalse($this->configuration()->narrow);
	}

	public function testAssertsNothingAboutCoverageUntilAProjectAsksForIt(): void {
		self::assertNull($this->configuration()->minCoverage);
	}

	public function testLeavesTheTimeoutAndTheThreadsToTheEngineByDefault(): void {
		self::assertNull($this->configuration()->timeout);
		self::assertNull($this->configuration()->threads);
		self::assertFalse($this->configuration()->mutateUncovered);
		self::assertNull($this->configuration()->tests);
	}

	public function testTakesAnExcludedPathApartIntoWhatTheEngineWants(): void {
		$this->givenMuleJson('{"source": ["src", "lib"], "exclude": ["src/External/Disk.php", "lib/Vendored"]}');

		self::assertSame(["External/Disk.php", "Vendored"], $this->configuration()->excludedWithinSource());
	}

	/**
	 * Excluded from the engine it would be silently excluded from nothing,
	 * while still being hidden from the coverage report — which is the one
	 * combination that can make a file look proven when nothing mutated it.
	 *
	 * @return void
	 */
	public function testRejectsAnExcludedPathThatIsNotInsideTheSource(): void {
		$this->givenMuleJson('{"exclude": ["docs/Example.php"]}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The excluded path docs/Example.php in mule.json is not inside any of the source directories, so mule cannot leave it out of the gate.");

		$this->configuration()->excludedWithinSource();
	}

	/**
	 * The engine hands its exclude list to a finder scanning every source
	 * directory at once, so a relative path cannot be scoped to the root it was
	 * written under. Mule asks the same question of a file as the engine will,
	 * so the two cannot disagree — without this, a second root holding the same
	 * relative path would be mutated by nothing, stay in the coverage report,
	 * and be banked as proven.
	 *
	 * @return void
	 */
	public function testExcludesTheSameRelativePathUnderEverySourceRoot(): void {
		$this->givenMuleJson('{"source": ["src", "lib"], "exclude": ["lib/Generated"]}');

		$configuration = $this->configuration();

		self::assertTrue($configuration->excludes("lib/Generated/One.php"));
		self::assertTrue(
			$configuration->excludes("src/Generated/One.php"),
			"the engine will not mutate this either, so mule must not bank it.",
		);
	}

	public function testExcludesNothingElseUnderThoseRoots(): void {
		$this->givenMuleJson('{"source": ["src", "lib"], "exclude": ["lib/Generated"]}');

		$configuration = $this->configuration();

		self::assertFalse($configuration->excludes("src/Cache/Keying.php"));
		self::assertFalse(
			$configuration->excludes("src/GeneratedThing.php"),
			"a name that merely starts the same way is a different file.",
		);
	}

	public function testExcludesNothingOutsideTheSourceRootsAtAll(): void {
		$this->givenMuleJson('{"exclude": ["src/Generated"]}');

		self::assertFalse($this->configuration()->excludes("tests/GeneratedTest.php"));
	}

	public function testRejectsAMuleJsonItCannotParse(): void {
		$this->givenMuleJson("{not json");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read mule.json: syntax error. It must be plain JSON — comments and trailing commas are not.");

		$this->configuration();
	}

	/**
	 * @param string $contents
	 * @return void
	 */
	private function givenMuleJson(string $contents): void {
		$this->filesystem->given("/project/mule.json", $contents);
	}

	/**
	 * @return Configuration
	 */
	private function configuration(): Configuration {
		return Configuration::load(Project::containing("/project", $this->filesystem), $this->filesystem, new CoveringProcesses($this->filesystem));
	}
}
