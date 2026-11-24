<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Json;

use Kristos80\Mule\Json\Decoded;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DecodedTest extends TestCase {

	public function testReadsAStringItIsGiven(): void {
		self::assertSame("etc/store.json", Decoded::of('{"store": "etc/store.json"}', "mule.json")->string("store"));
	}

	public function testFallsBackForAStringThatIsNotDeclared(): void {
		self::assertSame("infection", Decoded::of("{}", "mule.json")->stringOr("binary", "infection"));
	}

	public function testPrefersADeclaredStringOverItsFallback(): void {
		$declared = Decoded::of('{"binary": "./vendor/bin/infection"}', "mule.json");

		self::assertSame("./vendor/bin/infection", $declared->stringOr("binary", "infection"));
	}

	public function testReadsAListOfStrings(): void {
		$declared = Decoded::of('{"options": ["--threads=max", "--no-progress"]}', "mule.json");

		self::assertSame(["--threads=max", "--no-progress"], $declared->strings("options"));
	}

	public function testReadsAnUndeclaredListAsEmpty(): void {
		self::assertSame([], Decoded::of("{}", "mule.json")->strings("options"));
	}

	public function testReadsAnEmptyDeclaredList(): void {
		self::assertSame([], Decoded::of('{"options": []}', "mule.json")->strings("options"));
	}

	public function testReadsTheEntriesOfAnObjectOfObjects(): void {
		$entries = Decoded::of('{"src/A.php": {"source": "aa"}, "src/B.php": {"source": "bb"}}', "the mule store")
			->entries();

		self::assertSame(["src/A.php", "src/B.php"], array_keys($entries));
		self::assertSame("aa", $entries["src/A.php"]->string("source"));
		self::assertSame("bb", $entries["src/B.php"]->string("source"));
	}

	public function testReadsAnObjectWithNoEntries(): void {
		self::assertSame([], Decoded::of("{}", "the mule store")->entries());
	}

	public function testRejectsBytesThatAreNotJson(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read mule.json: mule needs it to be a JSON object.");

		Decoded::of("{not json", "mule.json");
	}

	public function testRejectsJsonThatIsNotAnObject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read the mule store: mule needs it to be a JSON object.");

		Decoded::of('["src/A.php"]', "the mule store");
	}

	public function testRejectsAMissingString(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The source in the mule store must be a non-empty string.");

		Decoded::of("{}", "the mule store")->string("source");
	}

	public function testRejectsAStringThatIsNotAString(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The binary in mule.json must be a non-empty string.");

		Decoded::of('{"binary": 7}', "mule.json")->string("binary");
	}

	public function testRejectsAnEmptyString(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The binary in mule.json must be a non-empty string.");

		Decoded::of('{"binary": ""}', "mule.json")->stringOr("binary", "infection");
	}

	public function testRejectsAListThatIsNotAList(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The options in mule.json must be a list of strings.");

		Decoded::of('{"options": "--threads=max"}', "mule.json")->strings("options");
	}

	public function testRejectsAListHoldingSomethingThatIsNotAString(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage in mule.json must be a list of strings.");

		Decoded::of('{"coverage": ["phpunit", 7]}', "mule.json")->strings("coverage");
	}

	public function testRejectsAnEntryThatIsNotAnObject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The entry for src/A.php in the mule store is malformed.");

		Decoded::of('{"src/A.php": "aa"}', "the mule store")->entries();
	}
}
