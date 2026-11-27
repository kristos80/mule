<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Doctor;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Doctor\Spread;
use Kristos80\Mule\Doctor\Tier;
use PHPUnit\Framework\TestCase;
use function sprintf;

final class TierTest extends TestCase {

	/**
	 * A project of `$files` gated files where each test runs `$footprint` of them,
	 * so every file's closure is exactly that footprint.
	 *
	 * @param int<0, max> $files
	 * @param int<1, max> $footprint
	 * @param int<0, max> $hot How many files additionally share one very wide test.
	 * @return Spread
	 */
	private function spread(int $files, int $footprint, int $hot = 0): Spread {
		$gated = [];

		for($n = 0; $n < $files; $n++) {
			$gated[] = sprintf("src/F%03d.php", $n);
		}

		$tests = [];

		foreach(array_chunk($gated, $footprint) as $index => $chunk) {
			$tests["tests/T{$index}Test.php"] = new Footprint("sig", $chunk);
		}

		if($hot > 0) {
			$tests["tests/HotTest.php"] = new Footprint("sig", array_slice($gated, 0, $hot));
		}

		return Spread::of($tests, $gated);
	}

	/**
	 * The application: median 11 of 404, 2.7 %. The dynamic closure suffices, and
	 * a verdict cache pays for itself.
	 *
	 * @return void
	 */
	public function testASparseProjectIsTierA(): void {
		$tier = Tier::of($this->spread(404, 11));

		self::assertSame("A", $tier->letter);
		self::assertSame("the median edit re-proves 11 of 404 file(s) — 2 %", $tier->because);
		self::assertSame("A verdict cache will pay for itself here.", $tier->advice);
	}

	/**
	 * Tier A with hot spots. Measured on the application: a shared base class
	 * reaching 53 % of the project and three value objects nearly everything
	 * constructs. The width is real rather than an artefact.
	 *
	 * @return void
	 */
	public function testASparseProjectWithHotSpotsIsTierB(): void {
		// 55 files are reached by a five-file test and nothing else, so the median
		// stays at 5; the other 45 share one wide test, so each of them reaches 45.
		$tier = Tier::of($this->spread(100, 5, hot: 45));

		self::assertSame("B", $tier->letter);
		self::assertStringContainsString("the median edit re-proves 5 of 100 file(s) — 5 %", $tier->because);
		self::assertStringContainsString("45 file(s) reach 40 % of the project or more", $tier->because);
		self::assertStringContainsString("close to a full run", $tier->advice);
	}

	/**
	 * **The advice that two failed attempts needed.** A 36-file library is dense by
	 * arithmetic, not because its tests are badly shaped — and its full gate is
	 * under a minute, so a cache has nothing to save.
	 *
	 * @return void
	 */
	public function testASmallDenseProjectIsToldItDoesNotNeedThis(): void {
		$tier = Tier::of($this->spread(36, 29));

		self::assertSame("C", $tier->letter);
		self::assertStringContainsString(
			"which on a project this size is arithmetic rather than a property of your tests",
			$tier->because,
		);
		self::assertStringContainsString("You do not need this tool.", $tier->advice);
	}

	/**
	 * The other kind of Tier C: big, and its tests run most of it. Here
	 * restructuring would pay, so the advice points at the footprints rather than
	 * at the project's size.
	 *
	 * @return void
	 */
	public function testALargeDenseProjectIsToldToLookAtItsTests(): void {
		$tier = Tier::of($this->spread(400, 300));

		self::assertSame("C", $tier->letter);
		self::assertStringContainsString("almost any edit re-proves almost everything", $tier->because);
		self::assertStringContainsString("test footprints rather than project size", $tier->advice);
	}

	/**
	 * The two kinds of Tier C call for opposite responses, so they must not share
	 * a sentence.
	 *
	 * @return void
	 */
	public function testTheTwoKindsOfTierCAreToldApart(): void {
		self::assertNotSame(
			Tier::of($this->spread(36, 29))->advice,
			Tier::of($this->spread(400, 300))->advice,
		);
	}

	/**
	 * **Every threshold here is a judgement, so the lines are held down.** A
	 * project at the boundary should land on the side the figures say, and a
	 * comparison that drifted by one would move a whole category of project.
	 *
	 * @return void
	 */
	public function testWhereTheDenseLineFalls(): void {
		self::assertSame("A", Tier::of($this->spread(100, 25))->letter);
		self::assertSame("C", Tier::of($this->spread(100, 26))->letter);
	}

	public function testWhereTheSmallProjectLineFalls(): void {
		self::assertStringContainsString(
			"You do not need this tool.",
			Tier::of($this->spread(99, 99))->advice,
		);
		self::assertStringContainsString(
			"test footprints rather than project size",
			Tier::of($this->spread(100, 100))->advice,
		);
	}

	public function testWhereTheHotSpotLineFalls(): void {
		// Multiples of the chunk width, so the hot test's reach is exactly its own
		// footprint rather than its footprint plus the chunk that straddles its end.
		self::assertSame("A", Tier::of($this->spread(100, 5, hot: 35))->letter);
		self::assertSame("B", Tier::of($this->spread(100, 5, hot: 40))->letter);
	}

	/**
	 * Every hot spot, not the first: a project with one wide base class and a
	 * project with forty of them want different conversations.
	 *
	 * @return void
	 */
	public function testItCountsEveryHotSpot(): void {
		self::assertStringContainsString(
			"45 file(s) reach 40 % of the project or more",
			Tier::of($this->spread(100, 5, hot: 45))->because,
		);
	}

	/**
	 * The whole sentence, because every clause of it is something a person reads
	 * and acts on.
	 *
	 * @return void
	 */
	public function testTheWholeOfWhatItSays(): void {
		$tier = Tier::of($this->spread(36, 29));

		self::assertSame(
			"the median edit re-proves 29 of 36 file(s) — 80 %, which on a project this size is"
			. " arithmetic rather than a property of your tests",
			$tier->because,
		);
		self::assertSame(
			"You do not need this tool. A project of 36 files has a gate short enough that a cache"
			. " has nothing to save, and installing one would add a failure mode and buy no time.",
			$tier->advice,
		);
	}

	public function testTheWholeOfWhatItSaysAboutAWideSuite(): void {
		$tier = Tier::of($this->spread(400, 300));

		self::assertSame(
			"the median edit re-proves 300 of 400 file(s) — 75 %, so almost any edit re-proves"
			. " almost everything",
			$tier->because,
		);
		self::assertSame(
			"Nothing is cacheable as it stands, and the cause is test footprints rather than"
			. " project size. Start with the widest below: a test that executes a large share of"
			. " the project puts every file it touches into every other one's closure.",
			$tier->advice,
		);
	}

	public function testTheWholeOfWhatItSaysAboutHotSpots(): void {
		$tier = Tier::of($this->spread(100, 5, hot: 45));

		self::assertSame(
			"the median edit re-proves 5 of 100 file(s) — 5 %, but 45 file(s) reach 40 % of the"
			. " project or more",
			$tier->because,
		);
		self::assertSame(
			"A verdict cache will pay for itself for most edits. The hot spots below are the"
			. " exceptions — usually a shared base class or a value object nearly everything"
			. " constructs — and an edit to one of them costs close to a full run.",
			$tier->advice,
		);
	}

	public function testAProjectWithNoGatedFilesAtAll(): void {
		$tier = Tier::of($this->spread(0, 1));

		self::assertSame("A", $tier->letter);
		self::assertSame("the median edit re-proves 0 of 0 file(s) — 0 %", $tier->because);
	}
}
