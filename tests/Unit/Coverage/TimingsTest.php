<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Timings;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;

final class TimingsTest extends TestCase {

	/**
	 * A JUnit report shaped the way PHPUnit writes one: a root suite, a
	 * class-level suite carrying `file` and `time`, and the test cases below it.
	 *
	 * @return string
	 */
	private function junit(): string {
		return <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<testsuites>
			  <testsuite name="unit" tests="3" time="0.300000">
			    <testsuite name="Demo\ZzzTest" file="/p/tests/ZzzTest.php" tests="1" time="0.200000">
			      <testcase name="testOne" class="Demo\ZzzTest" time="0.200000"/>
			    </testsuite>
			    <testsuite name="Demo\AaaTest" file="/p/tests/AaaTest.php" tests="2" time="0.100000">
			      <testcase name="testTwo" class="Demo\AaaTest" time="0.060000"/>
			      <testcase name="testThree" class="Demo\AaaTest" time="0.040000"/>
			    </testsuite>
			    <testsuite name="a trailing group with no file" tests="0" time="9.900000"/>
			  </testsuite>
			</testsuites>
			XML;
	}

	public function testReadsEveryClassLevelSuite(): void {
		// canonical order, not the order the report happened to list them in
		self::assertSame([
			"Demo\\AaaTest" => ["file" => "/p/tests/AaaTest.php", "time" => 0.1],
			"Demo\\ZzzTest" => ["file" => "/p/tests/ZzzTest.php", "time" => 0.2],
		], Timings::from($this->junit())->classes);
	}

	/**
	 * The root `<testsuite name="unit">` has no `file`, and timing it would add
	 * the whole suite's duration to every mutant that any test covers.
	 *
	 * @return void
	 */
	public function testIgnoresASuiteThatNamesNoFile(): void {
		self::assertArrayNotHasKey("unit", Timings::from($this->junit())->classes);
	}

	public function testRefusesAReportThatNamesNoTestClasses(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage(
			"A JUnit report named no test classes. Infection reads the time and the file"
			. " off the class-level <testsuite> nodes, and without them every mutant looks"
			. " slow enough to skip.",
		);

		Timings::from('<?xml version="1.0"?><testsuites><testsuite name="unit" time="0.1"/></testsuites>');
	}

	public function testRefusesASuiteWithAnEmptyName(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A JUnit report has a <testsuite> with an empty name.");

		Timings::from('<?xml version="1.0"?><testsuites><testsuite name="" file="/p/x.php" time="0.1"/></testsuites>');
	}

	/**
	 * A missing time reads as `0.0`, which is the one value that can never look
	 * slow — so it would hide the very gap it came from rather than report it.
	 *
	 * @return void
	 */
	public function testRefusesAClassWithNoTime(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('The JUnit report gives no time for the test class "Demo\\AaaTest".');

		Timings::from('<?xml version="1.0"?><testsuites><testsuite name="Demo\\AaaTest" file="/p/a.php"/></testsuites>');
	}

	public function testPassesOverASuiteWithAnEmptyFileAttribute(): void {
		self::assertSame(
			["Demo\\AaaTest" => ["file" => "/p/a.php", "time" => 0.1]],
			Timings::from(
				'<?xml version="1.0"?><testsuites>'
				. '<testsuite name="root" file="" time="9.9"/>'
				. '<testsuite name="Demo\\AaaTest" file="/p/a.php" time="0.1"/>'
				. '</testsuites>',
			)->classes,
		);
	}

	public function testRefusesMalformedXml(): void {
		$this->expectException(\DOMException::class);

		Timings::from("<testsuites");
	}

	/**
	 * Exactly what Infection reads — `//testsuite[@name="…"][1]`, then the `file`
	 * and `time` attributes — and nothing else. One node per class, so the `[1]`
	 * can only ever match the one.
	 *
	 * @return void
	 */
	public function testSynthesisesOneNodePerClassWithTheTwoAttributesThatAreRead(): void {
		$junit = Timings::from($this->junit())->junit();

		self::assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<testsuites>'
			. '<testsuite name="Demo\AaaTest" file="/p/tests/AaaTest.php" time="0.100000"/>'
			. '<testsuite name="Demo\ZzzTest" file="/p/tests/ZzzTest.php" time="0.200000"/>'
			. '</testsuites>' . "\n",
			$junit,
		);
	}

	/**
	 * The synthesised report is sorted, so the same timings give the same bytes
	 * however the classes were discovered.
	 *
	 * @return void
	 */
	public function testTheSynthesisedReportDoesNotDependOnDiscoveryOrder(): void {
		$forwards = Timings::of([
			"Demo\\AaaTest" => ["file" => "/p/a.php", "time" => 0.1],
			"Demo\\ZzzTest" => ["file" => "/p/z.php", "time" => 0.2],
		]);
		$backwards = Timings::of([
			"Demo\\ZzzTest" => ["file" => "/p/z.php", "time" => 0.2],
			"Demo\\AaaTest" => ["file" => "/p/a.php", "time" => 0.1],
		]);

		self::assertSame($forwards->junit(), $backwards->junit());
	}

	public function testSurvivesTheRoundTripThroughItsOwnReport(): void {
		$timings = Timings::from($this->junit());

		self::assertSame($timings->classes, Timings::from($timings->junit())->classes);
	}

	public function testNamesTheClassesItCannotTime(): void {
		self::assertSame(
			["Demo\\MissingTest", "Demo\\AlsoMissingTest"],
			Timings::from($this->junit())->missing([
				"Demo\\AaaTest",
				"Demo\\MissingTest",
				"Demo\\ZzzTest",
				"Demo\\AlsoMissingTest",
			]),
		);
	}

	public function testNamesNothingWhenEveryClassIsTimed(): void {
		self::assertSame([], Timings::from($this->junit())->missing(["Demo\\AaaTest", "Demo\\ZzzTest"]));
	}
}
