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

	public function testReadsAWholeNumber(): void {
		self::assertSame(9, Decoded::of('{"lines": 9}', "the mule store")->number("lines"));
	}

	public function testRejectsANumberThatIsNotOne(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The lines in the mule store must be a whole number.");

		Decoded::of('{"lines": "9"}', "the mule store")->number("lines");
	}

	public function testRejectsAMissingNumber(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The lines in the mule store must be a whole number.");

		Decoded::of("{}", "the mule store")->number("lines");
	}

	public function testReadsANestedObject(): void {
		$nested = Decoded::of('{"verified": {"digest": "aa"}}', "the mule store")->object("verified");

		self::assertSame("aa", $nested->string("digest"));
	}

	public function testReadsAnAbsentSectionAsEmpty(): void {
		self::assertSame([], Decoded::of("{}", "the mule store")->object("verified")->entries());
	}

	public function testRejectsASectionThatIsNotAnObject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The verified in the mule store must be an object.");

		Decoded::of('{"verified": "aa"}', "the mule store")->object("verified");
	}

	public function testReadsAListOfObjects(): void {
		$objects = Decoded::of('{"unreached": [{"name": "one"}, {"name": "two"}]}', "the mule store")
			->objects("unreached");

		self::assertCount(2, $objects);
		self::assertSame("one", $objects[0]->string("name"));
		self::assertSame("two", $objects[1]->string("name"));
	}

	public function testReadsAnAbsentListOfObjectsAsEmpty(): void {
		self::assertSame([], Decoded::of("{}", "the mule store")->objects("unreached"));
	}

	public function testRejectsAListOfObjectsThatIsNotAList(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The unreached in the mule store must be a list of objects.");

		Decoded::of('{"unreached": "one"}', "the mule store")->objects("unreached");
	}

	public function testRejectsAListHoldingSomethingThatIsNotAnObject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The unreached in the mule store must be a list of objects.");

		Decoded::of('{"unreached": [{"name": "one"}, "two"]}', "the mule store")->objects("unreached");
	}

	public function testRejectsBytesThatAreNotJson(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read mule.json: syntax error. It must be plain JSON — comments and trailing commas are not.");

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

	public function testReadsAWholeNumberThatNeedNotBeThere(): void {
		self::assertSame(15, Decoded::of('{"timeout": 15}', "mule.json")->numberOr("timeout", NULL));
		self::assertNull(Decoded::of("{}", "mule.json")->numberOr("timeout", NULL));
	}

	public function testReadsAFlagThatNeedNotBeThere(): void {
		self::assertTrue(Decoded::of('{"narrow": true}', "mule.json")->flag("narrow", FALSE));
		self::assertFalse(Decoded::of("{}", "mule.json")->flag("narrow", FALSE));
	}

	public function testRejectsAFlagThatIsNeitherTrueNorFalse(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The narrow in mule.json must be true or false.");

		Decoded::of('{"narrow": "yes"}', "mule.json")->flag("narrow", FALSE);
	}

	public function testReadsOnlyTheNamesTurnedOn(): void {
		$decoded = Decoded::of('{"mutators": {"one": true, "two": false, "three": true}}', "mule.json");

		self::assertSame(["one", "three"], $decoded->enabled("mutators"));
	}

	public function testRejectsAnEnabledNameThatIsNotAFlag(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The one in mule.json mutators must be true or false.");

		Decoded::of('{"mutators": {"one": 1}}', "mule.json")->enabled("mutators");
	}

	public function testNamesEveryKeyItCarries(): void {
		self::assertSame(["one", "two"], Decoded::of('{"one": 1, "two": 2}', "mule.json")->names());
	}

	public function testReadsPathsWrittenSinglyOrAsAList(): void {
		$decoded = Decoded::of('{"psr-4": {"One": "src/", "Two": ["lib", "extra/"]}}', "composer.json");

		self::assertSame(["src", "lib", "extra"], $decoded->paths("psr-4"));
	}

	public function testRejectsAPathThatIsNotOne(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The One in composer.json psr-4 must be a path or a list of them.");

		Decoded::of('{"psr-4": {"One": ""}}', "composer.json")->paths("psr-4");
	}

	/**
	 * `mule.json` is the only file a project writes, so a comment or a trailing
	 * comma in it is an easy thing to reach for — and "mule needs it to be a
	 * JSON object" points nowhere near the syntax.
	 *
	 * @return void
	 */
	public function testSaysWhatIsWrongWithBytesThatWillNotParse(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read mule.json: syntax error. It must be plain JSON — comments and trailing commas are not.");

		Decoded::of("{\n\t// a comment\n\t\"threads\": \"max\"\n}", "mule.json");
	}

	public function testStillComplainsSeparatelyAboutValidJsonThatIsNotAnObject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read mule.json: mule needs it to be a JSON object.");

		Decoded::of('["source"]', "mule.json");
	}
}
