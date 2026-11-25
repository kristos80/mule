<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Runner;

use Kristos80\Mule\Runner\Run;
use Kristos80\Mule\Runner\Scratch;
use Kristos80\Mule\Tests\Support\FixedRandom;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScratchTest extends TestCase {

	public function testTakesADirectoryNamedAfterATokenNothingCanGuess(): void {
		$filesystem = new InMemoryFilesystem();
		$run = (new Scratch($filesystem, new FixedRandom("cafe1234")))->create();

		self::assertSame("/tmp/mule.cafe1234", $run->directory);
		self::assertTrue($filesystem->isDirectory("/tmp/mule.cafe1234"));
	}

	public function testRefusesToJoinADirectoryThatIsAlreadyThere(): void {
		$filesystem = (new InMemoryFilesystem())->givenDirectory("/tmp/mule.cafe1234");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot create the directory /tmp/mule.cafe1234.");

		(new Scratch($filesystem, new FixedRandom("cafe1234")))->create();
	}

	public function testRemovesEverythingACoverageRunLeftBehind(): void {
		$filesystem = (new InMemoryFilesystem())
			->givenDirectory("/tmp/mule.cafe1234")
			->given("/tmp/mule.cafe1234/junit.xml", "<testsuites/>")
			->given("/tmp/mule.cafe1234/coverage-xml/index.xml", "<phpunit/>")
			->given("/tmp/mule.cafe1234/coverage-xml/Cache/Key.php.xml", "<phpunit/>");

		(new Scratch($filesystem, new FixedRandom()))->remove(new Run("/tmp/mule.cafe1234"));

		self::assertFalse($filesystem->exists("/tmp/mule.cafe1234"));
		self::assertSame(["/", "/tmp"], $filesystem->paths());
	}

	public function testLeavesTheRestOfTheTemporaryDirectoryAlone(): void {
		$filesystem = (new InMemoryFilesystem())
			->givenDirectory("/tmp/mule.cafe1234")
			->given("/tmp/mule.other/junit.xml", "<testsuites/>");

		(new Scratch($filesystem, new FixedRandom()))->remove(new Run("/tmp/mule.cafe1234"));

		self::assertTrue($filesystem->exists("/tmp/mule.other/junit.xml"));
	}

	public function testRemovesADirectoryThatIsNotThereWithoutComplaining(): void {
		$filesystem = new InMemoryFilesystem();

		(new Scratch($filesystem, new FixedRandom()))->remove(new Run("/tmp/mule.gone"));

		self::assertSame(["/"], $filesystem->paths());
	}

	public function testWritesTheEnginesConfigurationIntoTheRunsOwnDirectory(): void {
		$filesystem = (new InMemoryFilesystem())->givenDirectory("/tmp/mule.cafe1234");
		$run = new Run("/tmp/mule.cafe1234");

		(new Scratch($filesystem, new FixedRandom()))->declare($run, "{\"source\": {}}\n");

		self::assertSame("{\"source\": {}}\n", $filesystem->read($run->engineJson()));
	}

	/**
	 * The engine resolves a configuration's relative paths against that file's
	 * own directory, so what mule writes there belongs to the run and is swept
	 * up with the rest of it.
	 *
	 * @return void
	 */
	public function testTakesTheEnginesConfigurationAwayWithEverythingElse(): void {
		$filesystem = (new InMemoryFilesystem())->givenDirectory("/tmp/mule.cafe1234");
		$run = new Run("/tmp/mule.cafe1234");
		$scratch = new Scratch($filesystem, new FixedRandom());

		$scratch->declare($run, "{}");
		$scratch->remove($run);

		self::assertFalse($filesystem->exists($run->engineJson()));
	}

	public function testHandsBackTheLogTheEngineLeft(): void {
		$filesystem = (new InMemoryFilesystem())
			->givenDirectory("/tmp/mule.cafe1234")
			->given("/tmp/mule.cafe1234/mutants.json", '{"killed": []}');

		$mutants = (new Scratch($filesystem, new FixedRandom()))->mutants(new Run("/tmp/mule.cafe1234"));

		self::assertSame('{"killed": []}', $mutants);
	}

	/**
	 * An engine that died before writing one, or that was never asked, leaves
	 * mule with no per-file answer — and no answer means nothing is banked,
	 * which is the behaviour being replaced rather than a new failure.
	 *
	 * @return void
	 */
	public function testHandsBackNothingWhereTheEngineLeftNoLog(): void {
		$filesystem = (new InMemoryFilesystem())->givenDirectory("/tmp/mule.cafe1234");

		self::assertSame("", (new Scratch($filesystem, new FixedRandom()))->mutants(new Run("/tmp/mule.cafe1234")));
	}
}
