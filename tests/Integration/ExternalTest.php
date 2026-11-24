<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use Composer\Autoload\ClassLoader;
use Kristos80\Mule\External\ComposerClasses;
use Kristos80\Mule\External\PhpRandom;
use Kristos80\Mule\External\ShellProcesses;
use Kristos80\Mule\External\StreamOutput;
use Kristos80\Mule\Project;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The other four wrappers against the real thing: real child processes, a real
 * stream, the real CSPRNG, and this project's own Composer autoloader.
 */
final class ExternalTest extends TestCase {

	use TemporaryTree;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->createTree();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->removeTree();
	}

	public function testRunsACommandAndAnswersItsExitStatus(): void {
		$processes = new ShellProcesses();

		self::assertSame(0, $processes->run([PHP_BINARY, "-r", "exit(0);"], $this->path()));
		self::assertSame(3, $processes->run([PHP_BINARY, "-r", "exit(3);"], $this->path()));
	}

	public function testRunsACommandWhereItWasToldTo(): void {
		$processes = new ShellProcesses();
		$command = [PHP_BINARY, "-r", 'file_put_contents("here.txt", getcwd());'];

		self::assertSame(0, $processes->run($command, $this->path()));

		// The child reports its working directory resolved, which on this
		// machine means /private in front of the temporary directory.
		self::assertSame(realpath($this->path()), file_get_contents($this->path("here.txt")));
	}

	public function testRefusesACommandThatCannotStart(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot run");

		(new ShellProcesses())->run([$this->path("not-a-program")], $this->path());
	}

	public function testWritesOneLinePerCallToItsStream(): void {
		$stream = fopen("php://memory", "r+");

		self::assertIsResource($stream);

		$output = new StreamOutput($stream);

		$output->line("mule: 22 of 22 files verified, 0 stale.");
		$output->line("mule: nothing to mutate.");
		rewind($stream);

		self::assertSame(
			"mule: 22 of 22 files verified, 0 stale.\nmule: nothing to mutate.\n",
			stream_get_contents($stream),
		);
	}

	public function testMakesATokenNothingCanGuessAndNeverTheSameOneTwice(): void {
		$random = new PhpRandom();
		$token = $random->token();

		self::assertSame(16, strlen($token));
		self::assertSame($token, strtolower($token));
		self::assertNotSame($token, $random->token());
	}

	public function testFindsTheFileAClassLivesInThroughTheProjectsOwnAutoloader(): void {
		$classes = new ComposerClasses(self::loader());

		self::assertSame(
			realpath(__DIR__ . "/../../src/Project.php"),
			realpath((string) $classes->fileOf(Project::class)),
		);
	}

	public function testFindsTheFileOfATestClassDeclaredInAutoloadDev(): void {
		$classes = new ComposerClasses(self::loader());

		self::assertSame(realpath(__FILE__), realpath((string) $classes->fileOf(self::class)));
	}

	public function testPlacesNoFileForAClassTheAutoloaderDoesNotKnow(): void {
		self::assertFalse((new ComposerClasses(self::loader()))->fileOf("Acme\\Nothing\\Here"));
	}

	/**
	 * This project's own autoloader, which is the object `bin/mule` hands the
	 * wrapper: Composer's `autoload.php` returns the loader it built, and
	 * asking for the same file twice is the same object.
	 *
	 * @return ClassLoader
	 */
	private static function loader(): ClassLoader {
		$loader = require __DIR__ . "/../../vendor/autoload.php";

		self::assertInstanceOf(ClassLoader::class, $loader);

		return $loader;
	}
}
