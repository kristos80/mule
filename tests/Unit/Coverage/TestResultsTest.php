<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\TestResults;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;

final class TestResultsTest extends TestCase {

	public function testNarrowsAWellFormedMap(): void {
		self::assertSame(
			[
				"Demo\\AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.1],
				"Demo\\BbbTest::testTwo" => ["size" => "small", "status" => "failure", "time" => 0.0],
			],
			TestResults::of([
				"Demo\\AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.1],
				"Demo\\BbbTest::testTwo" => ["size" => "small", "status" => "failure", "time" => 0.0],
			], "A capture"),
		);
	}

	/**
	 * Only the three fields survive. Anything else `php-code-coverage` adds
	 * later would otherwise reach the renderer unexamined.
	 *
	 * @return void
	 */
	public function testKeepsOnlyTheThreeFieldsItKnowsAbout(): void {
		self::assertSame(
			["Demo\\AaaTest::testOne" => ["size" => "unknown", "status" => "success", "time" => 0.1]],
			TestResults::of([
				"Demo\\AaaTest::testOne" => [
					"size" => "unknown",
					"status" => "success",
					"time" => 0.1,
					"somethingNew" => "ignored",
				],
			], "A capture"),
		);
	}

	public function testRefusesANumericKey(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A capture has an entry under a key that is not a test id.");

		TestResults::of([["size" => "u", "status" => "success", "time" => 0.1]], "A capture");
	}

	public function testRefusesAnEmptyKey(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A capture has an entry under a key that is not a test id.");

		TestResults::of(["" => ["size" => "u", "status" => "success", "time" => 0.1]], "A capture");
	}

	public function testRefusesAnEntryThatIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage(
			'A capture gives "Demo\AaaTest::testOne" something other than a size, a status and a time.',
		);

		TestResults::of(["Demo\\AaaTest::testOne" => 0.1], "A capture");
	}

	public function testRefusesAMissingSize(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("something other than a size, a status and a time");

		TestResults::of(["Demo\\AaaTest::testOne" => ["status" => "success", "time" => 0.1]], "A capture");
	}

	public function testRefusesAMissingStatus(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("something other than a size, a status and a time");

		TestResults::of(["Demo\\AaaTest::testOne" => ["size" => "u", "time" => 0.1]], "A capture");
	}

	/**
	 * The field that matters most. A time read as something other than a float
	 * is how a mutant comes to look too slow to run (M12), and an integer
	 * arriving through JSON is the way that happens in practice.
	 *
	 * @return void
	 */
	public function testRefusesATimeThatIsNotAFloat(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("something other than a size, a status and a time");

		TestResults::of(["Demo\\AaaTest::testOne" => ["size" => "u", "status" => "success", "time" => 1]], "A capture");
	}

	public function testRefusesAMissingTime(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("something other than a size, a status and a time");

		TestResults::of(["Demo\\AaaTest::testOne" => ["size" => "u", "status" => "success"]], "A capture");
	}

	/**
	 * A bad capture and a bad shard have to read differently: they are found at
	 * different times and fixed in different ways.
	 *
	 * @return void
	 */
	public function testTheSubjectNamesWhereTheBadDataCameFrom(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A shard's test results has an entry under a key that is not a test id.");

		TestResults::of([["size" => "u", "status" => "success", "time" => 0.1]], "A shard's test results");
	}
}
