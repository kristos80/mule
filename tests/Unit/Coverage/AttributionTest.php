<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Attribution;
use Kristos80\Mule\Coverage\Capture;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class AttributionTest extends TestCase {

	/**
	 * `HubTest` covers both `Hub.php` and `Leaf.php`; `LeafTest` covers only
	 * `Leaf.php`. So `Leaf.php` is covered by two classes, and `Hub.php` becomes
	 * a dependency of `Leaf.php`'s verdict through the test they share — which is
	 * the whole point of the breadth.
	 *
	 * @return Attribution
	 */
	private function attribution(): Attribution {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([
			"Demo\\HubTest::testOne",
			"Demo\\LeafTest::testTwo",
			"Demo\\HubTest::testThree#a dataset",
		]);
		$coverage->setLineCoverage([
			// a NULL *before* a covered line, so passing over it is not the same
			// as stopping at it
			"Hub.php" => [1 => NULL, 2 => [0 => 1, 2 => 1]],
			"Leaf.php" => [3 => [0 => 1, 1 => 1], 4 => NULL],
			"Untouched.php" => [9 => []],
		]);

		return Attribution::of(Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/p/src",
			"codeCoverage" => $coverage,
			"testResults" => [],
		]));
	}

	public function testNamesOnlyTheFilesSomethingActuallyCovers(): void {
		self::assertSame(["Hub.php", "Leaf.php"], $this->attribution()->files());
	}

	public function testGivesTheTestClassesCoveringAFile(): void {
		self::assertSame(["Demo\\HubTest", "Demo\\LeafTest"], $this->attribution()->testsFor("Leaf.php"));
	}

	/**
	 * Two methods of one class are one covering class, because that is what the
	 * timings are keyed by and what the engine looks up.
	 *
	 * @return void
	 */
	public function testSeveralMethodsOfOneClassCountOnce(): void {
		self::assertSame(["Demo\\HubTest"], $this->attribution()->testsFor("Hub.php"));
	}

	public function testGivesNothingForAFileNobodyCovers(): void {
		self::assertSame([], $this->attribution()->testsFor("Untouched.php"));
	}

	/**
	 * The measured rule: everything the covering tests execute, which reaches
	 * past what the file itself mentions.
	 *
	 * @return void
	 */
	public function testADependencyArrivesThroughASharedTest(): void {
		// Leaf.php is covered by HubTest, which also runs Hub.php
		self::assertSame(["Hub.php", "Leaf.php"], $this->attribution()->depsFor("Leaf.php"));
	}

	public function testAFileWhoseTestsRunOnlyItDependsOnlyOnItself(): void {
		self::assertSame(["Hub.php", "Leaf.php"], $this->attribution()->depsFor("Hub.php"));
	}

	public function testGivesNoDependenciesForAFileNobodyCovers(): void {
		self::assertSame([], $this->attribution()->depsFor("Untouched.php"));
	}

	/**
	 * Everything comes out sorted, because the key is taken over these lists and
	 * the order a report happened to list coverage in is not a property of the
	 * project.
	 *
	 * @return void
	 */
	/**
	 * **Also the tripwire for a dependency.** The files come out sorted without
	 * `Attribution` sorting them, because
	 * `ProcessedCodeCoverageData::setLineCoverage()` sorts its own keys. The
	 * input here is deliberately unsorted, so if that ever stops being true this
	 * is what says so.
	 *
	 * @return void
	 */
	public function testEverythingIsCanonicallyOrdered(): void {
		$coverage = new ProcessedCodeCoverageData();
		// AaaTest sorts first but runs the later-sorting file, so a dependency
		// list built in iteration order comes out backwards unless it is sorted
		$coverage->setTestIds(["Demo\\AaaTest::testOne", "Demo\\ZzzTest::testTwo"]);
		$coverage->setLineCoverage([
			"Zzz.php" => [1 => [0 => 1]],
			"Aaa.php" => [1 => [1 => 1]],
			"Mmm.php" => [1 => [0 => 1, 1 => 1]],
		]);

		$attribution = Attribution::of(Capture::of([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/p/src",
			"codeCoverage" => $coverage,
			"testResults" => [],
		]));

		self::assertSame(["Aaa.php", "Mmm.php", "Zzz.php"], $attribution->files());
		self::assertSame(["Demo\\AaaTest", "Demo\\ZzzTest"], $attribution->testsFor("Mmm.php"));
		// AaaTest runs Mmm and Zzz; ZzzTest runs Aaa and Mmm. Iterated in test
		// order that is Mmm, Zzz, Aaa, Mmm — sorted it is Aaa, Mmm, Zzz
		self::assertSame(["Aaa.php", "Mmm.php", "Zzz.php"], $attribution->depsFor("Mmm.php"));
	}
}
