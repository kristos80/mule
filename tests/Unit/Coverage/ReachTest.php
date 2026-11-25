<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Reach;
use PHPUnit\Framework\TestCase;

final class ReachTest extends TestCase {

	public function testMeasuresNothingUntilSomethingIsAddedToIt(): void {
		$none = Reach::none();

		self::assertSame(0, $none->executable);
		self::assertSame(0, $none->executed);
		self::assertFalse($none->isMeasured());
	}

	public function testAddsBothCountsTogether(): void {
		$sum = (new Reach(10, 7))->plus(new Reach(4, 1));

		self::assertSame(14, $sum->executable);
		self::assertSame(8, $sum->executed);
	}

	public function testIsMeasuredOnceThereIsAnExecutableLineInIt(): void {
		self::assertTrue((new Reach(1, 0))->isMeasured());
	}

	public function testIsCompleteWhenEveryExecutableLineRan(): void {
		self::assertTrue((new Reach(12, 12))->isComplete());
	}

	public function testIsNotCompleteWhileOneLineIsUnreached(): void {
		self::assertFalse((new Reach(12, 11))->isComplete());
	}

	/**
	 * An interface or a file of constants, which the report lists and no test
	 * can execute. It is complete, and it moves no total.
	 *
	 * @return void
	 */
	public function testIsCompleteWithNothingExecutableInIt(): void {
		self::assertTrue(Reach::none()->isComplete());
	}

	public function testReachesAPercentageItIsExactlyAt(): void {
		self::assertTrue((new Reach(5, 4))->reaches(80));
	}

	public function testDoesNotReachAPercentageItIsOneLineShortOf(): void {
		self::assertFalse((new Reach(5, 3))->reaches(80));
	}

	/**
	 * Cross-multiplied rather than divided, so a project asked for 80 % and
	 * sitting on exactly four fifths does not turn on whether `0.8` is
	 * representable.
	 *
	 * @return void
	 */
	public function testReachesAPercentageNoFloatWouldLandOn(): void {
		self::assertTrue((new Reach(100, 29))->reaches(29));
	}

	public function testDoesNotReachAHundredPercentOneLineShortOfIt(): void {
		self::assertFalse((new Reach(101, 100))->reaches(100));
	}

	public function testWritesItselfAsTheStoreRecordsIt(): void {
		self::assertSame(["executable" => 9, "executed" => 5], (new Reach(9, 5))->toArray());
	}
}
