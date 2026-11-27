<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Config;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase {

	public function testAnEmptyConfigurationIsTheDefaultOne(): void {
		$configuration = Configuration::of([]);

		self::assertSame("src", $configuration->source);
		self::assertSame("tests", $configuration->tests);
		self::assertSame([], $configuration->support);
		self::assertSame([], $configuration->exclude);
		self::assertSame(["@default" => TRUE], $configuration->mutators);
		self::assertSame(100, $configuration->minCoverage);
		self::assertSame("max", $configuration->threads);
	}

	public function testItIsNotAnObject(): void {
		$this->expectExceptionMessage("mule.json must be an object, string given.");

		Configuration::of("src");
	}

	public function testATrailingSlashOnADirectoryIsNotADifferentDirectory(): void {
		self::assertSame("app", Configuration::of(["source" => "app/"])->source);
	}

	public function testADirectoryThatIsNotOne(): void {
		$this->expectExceptionMessage('mule.json gives "tests" as something other than a directory.');

		Configuration::of(["tests" => ""]);
	}

	/**
	 * Every directory a consumer names has to be inside the project, support
	 * directories included — and that is checked over all of them at once rather
	 * than at each use.
	 *
	 * @return void
	 */
	public function testEveryDirectoryNamedIsCheckedForBeingInsideTheProject(): void {
		$this->expectExceptionMessage('mule.json names "/shared/fixtures", which is not inside');

		Configuration::of(["source" => "src", "tests" => "spec", "support" => ["/shared/fixtures"]]);
	}

	public function testPathsAreSorted(): void {
		self::assertSame(["a", "b", "c"], Configuration::of(["support" => ["c", "a", "b"]])->support);
	}

	public function testSupportThatIsNotAList(): void {
		$this->expectExceptionMessage('mule.json gives "support" as something other than a list of paths.');

		Configuration::of(["support" => ["first" => "tests/Support"]]);
	}

	public function testAnExcludeEntryThatIsNotAPath(): void {
		$this->expectExceptionMessage('mule.json has a "exclude" entry that is not a path.');

		Configuration::of(["exclude" => [7]]);
	}

	/**
	 * A named file is excluded and so is everything under a named directory,
	 * because "this part is not mine to gate" is the usual thing somebody means
	 * and a per-file list goes stale the next time one is added.
	 *
	 * @return void
	 */
	public function testWhatCountsAsExcluded(): void {
		$configuration = Configuration::of(["exclude" => ["src/Legacy", "src/Generated.php"]]);

		self::assertTrue($configuration->excludes("src/Generated.php"));
		self::assertTrue($configuration->excludes("src/Legacy"));
		self::assertTrue($configuration->excludes("src/Legacy/Deep/Thing.php"));
		self::assertFalse($configuration->excludes("src/Generated.php.bak"));
		self::assertFalse($configuration->excludes("src/LegacyThing.php"));
		self::assertFalse($configuration->excludes("src/Kept.php"));
	}

	public function testNothingIsExcludedByDefault(): void {
		self::assertFalse(Configuration::of([])->excludes("src/A.php"));
	}

	public function testTheMutatorSetIsCanonical(): void {
		self::assertSame(
			["@default" => TRUE, "@identical" => FALSE],
			Configuration::of(["mutators" => ["@identical" => FALSE, "@default" => TRUE]])->mutators,
		);
	}

	public function testAGateWithNoMutatorsIsNoGate(): void {
		$this->expectExceptionMessage("mule.json must name at least one mutator; without one there is no gate.");

		Configuration::of(["mutators" => []]);
	}

	public function testAMutatorThatIsNotAnObject(): void {
		$this->expectExceptionMessage("mule.json must name at least one mutator; without one there is no gate.");

		Configuration::of(["mutators" => "@default"]);
	}

	public function testAMutatorThatIsNotANameAndAYesOrNo(): void {
		$this->expectExceptionMessage("mule.json gives a mutator that is not a name and a yes or no.");

		Configuration::of(["mutators" => ["@default" => "yes"]]);
	}

	public function testAMutatorWithNoName(): void {
		$this->expectExceptionMessage("mule.json gives a mutator that is not a name and a yes or no.");

		Configuration::of(["mutators" => ["" => TRUE]]);
	}

	public function testAMutatorNamedByAccident(): void {
		$this->expectExceptionMessage("mule.json gives a mutator that is not a name and a yes or no.");

		Configuration::of(["mutators" => [TRUE]]);
	}

	public function testAFloorOfZeroIsAllowed(): void {
		self::assertSame(0, Configuration::of(["min-coverage" => 0])->minCoverage);
	}

	public function testAFloorThatIsNotAPercentage(): void {
		$this->expectExceptionMessage('mule.json gives "min-coverage" as something other than a percentage.');

		Configuration::of(["min-coverage" => 101]);
	}

	public function testAFloorBelowNothing(): void {
		$this->expectExceptionMessage('mule.json gives "min-coverage" as something other than a percentage.');

		Configuration::of(["min-coverage" => -1]);
	}

	public function testAFloorThatIsNotAWholeNumber(): void {
		$this->expectExceptionMessage('mule.json gives "min-coverage" as something other than a percentage.');

		Configuration::of(["min-coverage" => 99.5]);
	}

	public function testACountOfThreads(): void {
		self::assertSame("4", Configuration::of(["threads" => 4])->threads);
	}

	public function testNoThreadsAtAll(): void {
		$this->expectExceptionMessage('mule.json gives "threads" as something other than "max" or a count.');

		Configuration::of(["threads" => 0]);
	}

	public function testThreadsThatAreNeither(): void {
		$this->expectExceptionMessage('mule.json gives "threads" as something other than "max" or a count.');

		Configuration::of(["threads" => "all of them"]);
	}

	/**
	 * **G7.** The store is committed and every path in it is project-relative, so
	 * a configuration carrying machine-local state is a store no other machine can
	 * use.
	 *
	 * @return void
	 */
	public function testAnAbsoluteRootIsRefused(): void {
		$this->expectExceptionMessage(
			'mule.json names "/home/ci/src", which is not inside the project. The store is committed'
			. ' and every path in it is project-relative, so nothing here may be machine-local.',
		);

		Configuration::of(["source" => "/home/ci/src"]);
	}

	public function testAnEscapingExcludeIsRefused(): void {
		$this->expectExceptionMessage(
			'mule.json names "../shared/Thing.php", which is not inside the project. The store is'
			. ' committed and every path in it is project-relative, so nothing here may be'
			. ' machine-local.',
		);

		Configuration::of(["exclude" => ["../shared/Thing.php"]]);
	}

	public function testAnEscapingSupportDirectoryIsRefused(): void {
		$this->expectException(Failure::class);

		Configuration::of(["support" => ["../fixtures"]]);
	}

	/**
	 * **Refused rather than ignored, which is the whole point of naming them.** A
	 * key that is read past is a key somebody sets, watches a green build, and
	 * believes did something — and narrowing in particular is a mode `keying.md`
	 * specifies and nothing has been built or measured against.
	 *
	 * @return void
	 */
	public function testNarrowingIsRefusedRatherThanIgnored(): void {
		$this->expectExceptionMessage(
			'mule.json sets "narrow", which this version does not implement. It is specified in'
			. ' docs/keying.md §6 and nothing has been built or measured against it, so it is refused'
			. ' rather than ignored — a key that is read past is a key you would believe did'
			. ' something.',
		);

		Configuration::of(["narrow" => TRUE]);
	}

	public function testACommentStrippedSignatureIsRefusedToo(): void {
		$this->expectExceptionMessage('mule.json sets "signature", which this version does not implement.');

		Configuration::of(["signature" => "code"]);
	}

	/**
	 * A typo in `min-coverage` is a floor nobody set, and the only sign of it is a
	 * gate that quietly asks for less than somebody wrote down.
	 *
	 * @return void
	 */
	public function testAKeyMuleDoesNotKnowIsRefused(): void {
		$this->expectExceptionMessage(
			'mule.json gives "min_coverage", which mule does not know. The keys are source, tests,'
			. ' support, exclude, mutators, min-coverage, threads.',
		);

		Configuration::of(["min_coverage" => 90]);
	}

	public function testEveryKeyItDoesKnowIsAccepted(): void {
		self::assertSame(90, Configuration::of([
			"source" => "app",
			"tests" => "spec",
			"support" => ["fixtures"],
			"exclude" => ["app/Generated.php"],
			"mutators" => ["@default" => TRUE],
			"min-coverage" => 90,
			"threads" => 2,
		])->minCoverage);
	}
}
