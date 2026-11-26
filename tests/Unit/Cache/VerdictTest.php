<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Verdict;
use PHPUnit\Framework\TestCase;

final class VerdictTest extends TestCase {

	public function testHoldsWhileTheKeyIsTheOneItWasProvenUnder(): void {
		self::assertTrue(new Verdict("a-key", 4)->holds("a-key"));
	}

	public function testDoesNotHoldOnceAnythingItRestsOnHasMoved(): void {
		self::assertFalse(new Verdict("a-key", 4)->holds("another-key"));
	}

	public function testCarriesHowManyMutantsItProved(): void {
		self::assertSame(4, new Verdict("a-key", 4)->mutants);
	}

	/**
	 * Zero is a claim: the file was examined and has nothing to mutate.
	 *
	 * @return void
	 */
	public function testZeroMutantsIsAVerdictRatherThanAnAbsence(): void {
		self::assertTrue(new Verdict("a-key", 0)->holds("a-key"));
	}
}
