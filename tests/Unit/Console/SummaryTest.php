<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Cache\Footprint;
use Kristos80\Mule\Cache\Lapse;
use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Standing;
use Kristos80\Mule\Cache\Verdict;
use Kristos80\Mule\Console\Summary;
use Kristos80\Mule\Runner\Finding;
use PHPUnit\Framework\TestCase;
use function implode;

final class SummaryTest extends TestCase {

	/**
	 * A store proving `A.php` with four kills.
	 *
	 * @param list<string> $unproven
	 * @return Snapshot
	 */
	private function store(array $unproven = []): Snapshot {
		return new Snapshot(
			["src/A.php" => new Verdict("key-a", 4)],
			$this->lapses($unproven),
			["tests/Unit/AaaTest.php" => new Footprint("t", ["src/A.php"])],
			"chain",
		);
	}

	/**
	 * @param Snapshot $store
	 * @param list<string> $gated
	 * @return Standing
	 */
	private function standing(Snapshot $store, array $gated = ["src/A.php"]): Standing {
		return Standing::of($store, ["src/A.php" => "a", "tests/Unit/AaaTest.php" => "t"], $gated, "chain");
	}

	/**
	 * @param Finding $finding
	 * @param list<string> $excluded
	 * @return string
	 */
	private function text(Finding $finding, array $excluded = []): string {
		return implode("\n", new Summary()->lines($finding, $excluded));
	}

	/**
	 * A reason the store would carry for a file nothing proved.
	 *
	 * @param list<string> $paths
	 * @return array<string, Lapse>
	 */
	private function lapses(array $paths): array {
		$lapses = [];

		foreach($paths as $path) {
			$lapses[$path] = new Lapse(4, 3, 1, 0, 10, 10);
		}

		return $lapses;
	}

	public function testNothingOwedSaysSoAndSaysWhy(): void {
		$store = $this->store();

		self::assertStringContainsString(
			"Nothing is owed, so nothing was measured — the store describes this tree.",
			$this->text(Finding::settled($store, $this->standing($store))),
		);
	}

	public function testAPartialRunSaysWhatItWasOwedAndWhatItAsked(): void {
		$store = $this->store();

		self::assertStringContainsString(
			"1 file(s) were owed; 1 were handed to the engine.",
			$this->text(Finding::proven(
				$store,
				$this->standing(new Snapshot([], $this->lapses(["src/A.php"]), ["tests/Unit/AaaTest.php" => new Footprint("t", [])], "chain")),
				["src/A.php" => "key-a"],
				[],
			)),
		);
	}

	/**
	 * A cold run says *why* it was cold, because "everything was re-proven" with
	 * no reason reads as mule having ignored its own cache.
	 *
	 * @return void
	 */
	public function testAWholeRunSaysWhyItWasWhole(): void {
		$store = $this->store();
		$unusable = Standing::of($store, ["src/A.php" => "a"], ["src/A.php"], "a different gate");

		self::assertStringStartsWith(
			"Everything was re-proven, because the verification environment has changed, so nothing"
			. " the store holds was proven under it.\n1 file(s) handed to the engine.\n",
			$this->text(Finding::proven($store, $unusable, ["src/A.php" => "key-a"], [])),
		);
	}

	/**
	 * **Every state is named, never implied by absence.** A file missing from the
	 * output is indistinguishable from a file nobody thought about, which is how a
	 * mutable file stops being gated without anybody noticing.
	 *
	 * @return void
	 */
	public function testTheCountsAddUpToTheTree(): void {
		$store = $this->store(["src/B.php"]);

		$text = $this->text(
			Finding::proven($store, $this->standing($store, ["src/A.php", "src/B.php"]), [], []),
			["src/Generated.php"],
		);

		self::assertStringContainsString("proven      1 file(s), 4 mutant(s) killed", $text);
		self::assertStringContainsString("not proven  1 file(s)", $text);
		self::assertStringContainsString("excluded    1 file(s), not gated at all", $text);
	}

	/**
	 * The whole of it, once, because every line here is something a person reads
	 * and a missing blank line or heading is not something an assertion about one
	 * substring would notice.
	 *
	 * @return void
	 */
	public function testTheWholeOfWhatItSays(): void {
		$store = $this->store(["src/B.php"]);

		self::assertSame(
			"2 file(s) were owed; 1 were handed to the engine.\n"
			. "\n"
			. "  proven      1 file(s), 4 mutant(s) killed\n"
			. "  not proven  1 file(s)\n"
			. "  excluded    1 file(s), not gated at all\n"
			. "\n"
			. "Not proven, and was not before this run either:\n"
			. "  src/B.php — 1 of 4 mutant(s) escaped\n"
			. "\n"
			. "Not banked, edited while the engine ran:\n"
			. "  src/Z.php\n"
			. "\n"
			. "NOT PROVEN: 1 file(s) have mutants nothing killed.",
			$this->text(
				Finding::proven(
					$store,
					$this->standing($store, ["src/A.php", "src/B.php"]),
					["src/A.php" => "key-a"],
					["src/Z.php"],
				),
				["src/Generated.php"],
			),
		);
	}

	public function testAProvenProjectSaysSoPlainly(): void {
		$store = $this->store();

		self::assertStringContainsString(
			"Proven: every gated file, every mutant killed.",
			$this->text(Finding::settled($store, $this->standing($store))),
		);
	}

	/**
	 * **What this run broke and what it inherited are different messages.** "You
	 * have just broken this" is something somebody acts on; "this has been broken
	 * since before you started" is a backlog, and one list for both makes the first
	 * invisible inside the second.
	 *
	 * @return void
	 */
	public function testWhatThisRunFoundIsSeparateFromWhatItInherited(): void {
		$store = $this->store(["src/B.php", "src/C.php"]);

		$text = $this->text(Finding::proven(
			$store,
			$this->standing($store, ["src/A.php", "src/B.php", "src/C.php"]),
			["src/A.php" => "key-a", "src/B.php" => "key-b"],
			[],
		));

		self::assertStringContainsString(
			"Not proven, found by this run:\n  src/B.php — 1 of 4 mutant(s) escaped\n",
			$text,
		);
		self::assertStringContainsString(
			"Not proven, and was not before this run either:\n  src/C.php — 1 of 4 mutant(s) escaped\n",
			$text,
		);
		self::assertStringContainsString("NOT PROVEN: 2 file(s) have mutants nothing killed.", $text);
	}

	public function testAProvenProjectListsNothing(): void {
		$store = $this->store();

		self::assertStringNotContainsString(
			"Not proven",
			$this->text(Finding::settled($store, $this->standing($store))),
		);
	}

	/**
	 * A run that measured nothing found nothing, so everything unproven is
	 * inherited.
	 *
	 * @return void
	 */
	public function testARunThatMeasuredNothingInheritsEveryEscape(): void {
		$store = $this->store(["src/B.php"]);

		self::assertStringContainsString(
			"Not proven, and was not before this run either:\n  src/B.php — 1 of 4 mutant(s) escaped",
			$this->text(Finding::settled($store, $this->standing($store))),
		);
	}

	/**
	 * A file edited while the engine ran has a verdict belonging to neither
	 * version of it, so it is left out of the store — and somebody has to be told,
	 * or the next run's extra work looks arbitrary.
	 *
	 * @return void
	 */
	public function testAFileAbandonedMidRunIsReported(): void {
		$store = $this->store();

		self::assertStringContainsString(
			"Not banked, edited while the engine ran:\n  src/Z.php",
			$this->text(Finding::proven($store, $this->standing($store), [], ["src/Z.php"])),
		);
	}

	public function testNothingAbandonedListsNothing(): void {
		$store = $this->store();

		self::assertStringNotContainsString(
			"Not banked",
			$this->text(Finding::settled($store, $this->standing($store))),
		);
	}

	/**
	 * **Said out loud, because otherwise the run looks like work for nothing.** A
	 * changed test file has to be captured again before anything can be keyed
	 * against it, and that can be the only reason a run measured anything — in
	 * which case nothing was owed and nothing reached the engine.
	 *
	 * @return void
	 */
	public function testARunThatOnlyReMeasuredSaysSo(): void {
		$store = new Snapshot(
			["src/A.php" => new Verdict("key-a", 4)],
			$this->lapses([]),
			["tests/Unit/AaaTest.php" => new Footprint("the bytes it had then", ["src/A.php"])],
			"chain",
		);

		$text = $this->text(Finding::proven($store, $this->standing($store), [], []));

		self::assertStringContainsString(
			"1 test file(s) had changed since they were measured, so their coverage was captured"
			. " again.\n1 file(s) were owed; 0 were handed to the engine.",
			$text,
		);
	}

	public function testARunThatReMeasuredNothingSaysNothingAboutIt(): void {
		$store = $this->store();

		self::assertStringNotContainsString(
			"had changed since they were measured",
			$this->text(Finding::proven($store, $this->standing($store), [], [])),
		);
	}
}
