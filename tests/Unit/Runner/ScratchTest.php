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
}
