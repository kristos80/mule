<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Domain\Ownership;

use Kristos80\Mule\Domain\Exception\DiscoveryException;
use Kristos80\Mule\Domain\Inventory\TestInventory;
use Kristos80\Mule\Domain\Ownership\OwnershipReader;
use PHPUnit\Framework\TestCase;
use function array_keys;
use function implode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

final class OwnershipReaderTest extends TestCase {

	private OwnershipReader $reader;

	protected function setUp(): void {
		$this->reader = new OwnershipReader();
	}

	public function testCreditsEachTestFileWithTheSourcesItsMethodsExecute(): void {
		$map = $this->reader->read(
			$this->jsonl([
				"Acme\\HubTest::testOne" => ["Hub.php" => ["6"]],
				"Acme\\HubTest::testTwo" => ["Leaf.php" => ["7"]],
			]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);

		self::assertSame(["src/Hub.php", "src/Leaf.php"], $map->owns["tests/Unit/HubTest.php"]);
	}

	public function testRecordsTheReverseDirectionTheSameWay(): void {
		$map = $this->reader->read(
			$this->jsonl([
				"Acme\\HubTest::testOne" => ["Hub.php" => ["6"]],
				"Acme\\LeafTest::testOne" => ["Hub.php" => ["7"]],
			]),
			$this->inventory([
				"Acme\\HubTest" => "tests/Unit/HubTest.php",
				"Acme\\LeafTest" => "tests/Unit/LeafTest.php",
			]),
			"src",
		);

		self::assertSame(
			["tests/Unit/HubTest.php", "tests/Unit/LeafTest.php"],
			$map->owners["src/Hub.php"],
		);
	}

	/**
	 * A test that executes no instrumented source never appears in the coverage
	 * report. Seeding every discovered test file is what keeps "covers nothing"
	 * distinguishable from "never discovered" — the two call for opposite
	 * responses, and a source that later acquires this test as an owner must be
	 * re-keyed rather than silently banked.
	 */
	public function testKeepsADiscoveredTestFileThatCoversNothing(): void {
		$map = $this->reader->read(
			$this->jsonl(["Acme\\HubTest::testOne" => ["Hub.php" => ["6"]]]),
			$this->inventory([
				"Acme\\HubTest" => "tests/Unit/HubTest.php",
				"Acme\\SilentTest" => "tests/Unit/SilentTest.php",
			]),
			"src",
		);

		self::assertArrayHasKey("tests/Unit/SilentTest.php", $map->owns);
		self::assertSame([], $map->owns["tests/Unit/SilentTest.php"]);
	}

	/**
	 * Two runs of the same suite must produce byte-identical relations or every
	 * identity misses, and `executionOrder="random"` changes the order records
	 * arrive in on purpose.
	 */
	public function testOrdersBothDirectionsSoTwoRunsOfTheSameSuiteAgree(): void {
		$map = $this->reader->read(
			$this->jsonl([
				"Acme\\HubTest::testOne" => ["Zebra.php" => ["1"], "Apple.php" => ["2"]],
			]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);

		self::assertSame(["src/Apple.php", "src/Zebra.php"], $map->owns["tests/Unit/HubTest.php"]);
	}

	public function testPrefixesCoveredPathsWithTheReportsOwnSourceRoot(): void {
		$map = $this->reader->read(
			$this->jsonl(["Acme\\HubTest::testOne" => ["Domain/Hub.php" => ["6"]]]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"app/library",
		);

		self::assertSame(["app/library/Domain/Hub.php"], $map->owns["tests/Unit/HubTest.php"]);
	}

	public function testTreatsAnEmptySourceRootAsNoPrefixAtAll(): void {
		$map = $this->reader->read(
			$this->jsonl(["Acme\\HubTest::testOne" => ["Hub.php" => ["6"]]]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"",
		);

		self::assertSame(["Hub.php"], $map->owns["tests/Unit/HubTest.php"]);
	}

	public function testIgnoresBlankLinesInTheRecords(): void {
		$map = $this->reader->read(
			"\n" . $this->jsonl(["Acme\\HubTest::testOne" => ["Hub.php" => ["6"]]]) . "\n\n",
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);

		self::assertSame(["src/Hub.php"], $map->owns["tests/Unit/HubTest.php"]);
	}

	public function testReadsATestIdentifierThatNamesNoMethod(): void {
		$map = $this->reader->read(
			$this->jsonl(["Acme\\HubTest" => ["Hub.php" => ["6"]]]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);

		self::assertSame(["src/Hub.php"], $map->owns["tests/Unit/HubTest.php"]);
	}

	/**
	 * An inventory and a report from two different runs would attribute coverage
	 * to a file mule never discovered, so the mismatch is a refusal rather than a
	 * silently dropped record.
	 */
	public function testRefusesCoverageCreditedToAClassTheInventoryDoesNotList(): void {
		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage(
			"The coverage report credits \"Acme\\StrangerTest::testOne\" to class"
			. " \"Acme\\StrangerTest\", which PHPUnit's test inventory does not list. The inventory"
			. " and the report came from different runs, or the suite changed between them.",
		);

		$this->reader->read(
			$this->jsonl(["Acme\\StrangerTest::testOne" => ["Hub.php" => ["6"]]]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);
	}

	public function testRefusesARecordThatIsNotValidJsonAndNamesTheLine(): void {
		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage(
			"Line 2 of the coverage report's per-test records is not valid JSON: Syntax error",
		);

		$this->reader->read(
			$this->jsonl(["Acme\\HubTest::testOne" => ["Hub.php" => ["6"]]]) . "\n{\"test\":",
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);
	}

	public function testRefusesARecordWithNoCoversMember(): void {
		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage(
			"Line 1 of the coverage report's per-test records does not name a \"test\" and the files"
			. " it \"covers\".",
		);

		$this->reader->read(
			"{\"test\":\"Acme\\\\HubTest::testOne\"}",
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);
	}

	public function testReadsNoRecordsAsAnEmptyRelation(): void {
		$map = $this->reader->read("", new TestInventory([], []), "src");

		self::assertSame([], $map->owns);
		self::assertSame([], $map->owners);
	}


	/**
	 * The relation is hashed, so its key order is part of the identity and not
	 * only its values.
	 */
	public function testOrdersTheTestFileKeysSoTwoRunsHashIdentically(): void {
		$map = $this->reader->read(
			$this->jsonl([
				"Acme\\ZTest::testOne" => ["Hub.php" => ["1"]],
				"Acme\\ATest::testOne" => ["Hub.php" => ["2"]],
			]),
			$this->inventory([
				"Acme\\ZTest" => "tests/Unit/ZTest.php",
				"Acme\\ATest" => "tests/Unit/ATest.php",
			]),
			"src",
		);

		self::assertSame(["tests/Unit/ATest.php", "tests/Unit/ZTest.php"], array_keys($map->owns));
	}

	public function testOrdersTheSourceFileKeysTheSameWay(): void {
		$map = $this->reader->read(
			$this->jsonl([
				"Acme\\HubTest::testOne" => ["Zebra.php" => ["1"], "Apple.php" => ["2"]],
			]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);

		self::assertSame(["src/Apple.php", "src/Zebra.php"], array_keys($map->owners));
	}

	/**
	 * A line of only whitespace is not an empty line, and reaching the JSON parser
	 * with one would turn a cosmetic blank into a refusal.
	 */
	public function testIgnoresALineHoldingOnlyWhitespace(): void {
		$map = $this->reader->read(
			$this->jsonl(["Acme\\HubTest::testOne" => ["Hub.php" => ["6"]]]) . "\n   \t  ",
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"src",
		);

		self::assertSame(["src/Hub.php"], $map->owns["tests/Unit/HubTest.php"]);
	}

	/**
	 * PHP turns a numeric-string array key into an integer, so a source file named
	 * only with digits comes back out of the record as an `int`. Without the cast
	 * it would reach the identity as a different type than every other path.
	 */
	public function testKeepsANumericallyNamedSourceFileAsAString(): void {
		$map = $this->reader->read(
			$this->jsonl(["Acme\\HubTest::testOne" => ["123" => ["6"]]]),
			$this->inventory(["Acme\\HubTest" => "tests/Unit/HubTest.php"]),
			"",
		);

		self::assertSame(["123"], $map->owns["tests/Unit/HubTest.php"]);
	}

	/**
	 * @param array<string, string> $classes
	 * @return TestInventory
	 */
	private function inventory(array $classes): TestInventory {
		$files = [];

		foreach($classes as $file) {
			$files[$file] = TRUE;
		}

		return new TestInventory($classes, array_keys($files));
	}

	/**
	 * @param array<string, array<string, list<string>>> $records
	 * @return string
	 */
	private function jsonl(array $records): string {
		$lines = [];

		foreach($records as $test => $covers) {
			$lines[] = json_encode(["test" => $test, "covers" => $covers], JSON_THROW_ON_ERROR);
		}

		return implode("\n", $lines);
	}
}
