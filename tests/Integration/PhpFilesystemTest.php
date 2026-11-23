<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use Kristos80\Mule\External\PhpFilesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The filesystem wrapper against a real one.
 *
 * These assert that each method reaches the primitive it names, which is all
 * there is in it to be wrong — the decisions made with the answers are the unit
 * suite's business, over an array.
 */
#[CoversClass(PhpFilesystem::class)]
final class PhpFilesystemTest extends TestCase {

	use TemporaryTree;

	private PhpFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->createTree();

		$this->filesystem = new PhpFilesystem();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->removeTree();
	}

	public function testWritesAndReadsBackAFile(): void {
		$this->filesystem->write($this->path("verified.json"), "{}\n");

		self::assertSame("{}\n", $this->filesystem->read($this->path("verified.json")));
	}

	public function testAnswersWhatExistsAndWhatIsADirectory(): void {
		$this->write("src/A.php", "<?php");

		self::assertTrue($this->filesystem->exists($this->path("src/A.php")));
		self::assertTrue($this->filesystem->exists($this->path("src")));
		self::assertFalse($this->filesystem->exists($this->path("src/Gone.php")));
		self::assertTrue($this->filesystem->isDirectory($this->path("src")));
		self::assertFalse($this->filesystem->isDirectory($this->path("src/A.php")));
	}

	public function testDigestsAFileTheWayTheStoreRecordsIt(): void {
		$this->write("src/A.php", "<?php class A {}");

		self::assertSame(hash("sha256", "<?php class A {}"), $this->filesystem->digest($this->path("src/A.php")));
	}

	public function testMovesAFileOverAnother(): void {
		$this->write("verified.json", "old");
		$this->write("verified.json.cafe", "new");

		$this->filesystem->rename($this->path("verified.json.cafe"), $this->path("verified.json"));

		self::assertSame("new", $this->filesystem->read($this->path("verified.json")));
		self::assertFalse($this->filesystem->exists($this->path("verified.json.cafe")));
	}

	public function testRemovesAFileAndAnEmptyDirectory(): void {
		$this->write("run/junit.xml", "<testsuites/>");

		$this->filesystem->delete($this->path("run/junit.xml"));
		$this->filesystem->deleteDirectory($this->path("run"));

		self::assertFalse($this->filesystem->exists($this->path("run")));
	}

	public function testCreatesADirectoryAndEveryParentItNeeds(): void {
		$this->filesystem->createDirectory($this->path("etc/mule/store"));

		self::assertTrue($this->filesystem->isDirectory($this->path("etc/mule/store")));
	}

	public function testClaimsADirectoryNoOneElseHas(): void {
		$this->filesystem->createDirectoryExclusively($this->path("run"));

		self::assertTrue($this->filesystem->isDirectory($this->path("run")));
	}

	public function testRefusesToClaimADirectoryThatIsAlreadyThere(): void {
		$this->filesystem->createDirectoryExclusively($this->path("run"));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot create the directory");

		$this->filesystem->createDirectoryExclusively($this->path("run"));
	}

	public function testListsEveryEntryUnderADirectoryAtAnyDepth(): void {
		$this->write("run/junit.xml", "<testsuites/>");
		$this->write("run/coverage-xml/index.xml", "<phpunit/>");
		$this->write("run/coverage-xml/Cache/Key.php.xml", "<phpunit/>");

		$entries = $this->filesystem->entriesUnder($this->path("run"));

		sort($entries);

		self::assertSame([
			$this->path("run/coverage-xml"),
			$this->path("run/coverage-xml/Cache"),
			$this->path("run/coverage-xml/Cache/Key.php.xml"),
			$this->path("run/coverage-xml/index.xml"),
			$this->path("run/junit.xml"),
		], $entries);
	}

	public function testResolvesAPathWithoutItsSymlinks(): void {
		$this->filesystem->createDirectory($this->path("project/src"));
		symlink($this->path("project/src"), $this->path("linked"));

		self::assertSame(
			$this->filesystem->resolve($this->path("project/src")),
			$this->filesystem->resolve($this->path("linked")),
		);
	}

	public function testNamesWhereThisMachineKeepsTemporaryFiles(): void {
		self::assertTrue($this->filesystem->isDirectory($this->filesystem->temporaryDirectory()));
	}

	public function testRefusesToReadAFileThatIsNotThere(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read {$this->path("gone.json")}.");

		$this->filesystem->read($this->path("gone.json"));
	}

	public function testRefusesToHashAFileThatIsNotThere(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot hash");

		$this->filesystem->digest($this->path("gone.php"));
	}

	public function testRefusesToResolveAPathThatIsNotThere(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("no such directory");

		$this->filesystem->resolve($this->path("gone"));
	}

	public function testRefusesToWriteWhereNoDirectoryIs(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot write");

		$this->filesystem->write($this->path("gone/verified.json"), "{}");
	}

	public function testRefusesToRemoveWhatIsNotThere(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot remove the file");

		$this->filesystem->delete($this->path("gone.json"));
	}

	public function testRefusesToRemoveADirectoryThatIsNotEmpty(): void {
		$this->write("run/junit.xml", "<testsuites/>");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot remove the directory");

		$this->filesystem->deleteDirectory($this->path("run"));
	}

	public function testRefusesToMoveAFileThatIsNotThere(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot move");

		$this->filesystem->rename($this->path("gone.json"), $this->path("verified.json"));
	}
}
