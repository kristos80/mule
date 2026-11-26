<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Console\Composition;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use Kristos80\Mule\Tests\Support\RecordingReports;
use Kristos80\Mule\Tests\Support\ScriptedProcesses;
use Kristos80\Mule\Tests\Support\StubClasses;
use Kristos80\Mule\Tests\Support\StubEnvironment;
use Kristos80\Mule\Tests\Support\StubLines;
use PHPUnit\Framework\TestCase;

final class CompositionTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	protected function setUp(): void {
		$this->filesystem = new InMemoryFilesystem(
			files: [
				"/p/composer.lock" => '{"packages":[]}',
				"/p/phpunit.xml" => "<phpunit/>",
				"/p/src/A.php" => "<?php // a",
			],
			directories: ["/p/src"],
		);
	}

	/**
	 * @return Composition
	 */
	private function composition(): Composition {
		return new Composition(
			$this->filesystem,
			new ScriptedProcesses(),
			new StubClasses(),
			new RecordingReports(),
			new StubLines(),
			new StubEnvironment(),
		);
	}

	/**
	 * **An absent `mule.json` is a configured project, not an error.** Its
	 * defaults are what a consumer following the HOWTO would have written out by
	 * hand.
	 *
	 * @return void
	 */
	public function testAProjectWithNoConfigurationAtAll(): void {
		self::assertSame(["src/A.php"], $this->composition()->assembled()->universe->sources());
	}

	public function testItReadsMuleJsonWhenThereIsOne(): void {
		$this->filesystem->files["/p/mule.json"] = '{"exclude": ["src/A.php"]}';

		self::assertSame(["src/A.php"], $this->composition()->assembled()->universe->excluded());
	}

	/**
	 * **A missing input is refused rather than digested as absent.** A verdict
	 * proven without a lock file was proven against whatever happened to be
	 * installed, and a store full of those agrees with itself and nothing else
	 * (P3).
	 *
	 * @return void
	 */
	public function testThereIsNoLockFile(): void {
		unset($this->filesystem->files["/p/composer.lock"]);

		$this->expectExceptionMessage(
			"mule needs composer.lock, because it decides the pinned toolchain mule digests and"
			. " therefore what a verdict means. Nothing can be proven without it.",
		);

		$this->composition()->assembled();
	}

	/**
	 * mule starts PHPUnit the way PHPUnit would start itself, so the configuration
	 * it will find is the one mule has to digest.
	 *
	 * @return void
	 */
	public function testThereIsNoPhpunitConfiguration(): void {
		unset($this->filesystem->files["/p/phpunit.xml"]);

		$this->expectExceptionMessage(
			"mule found none of phpunit.xml, phpunit.xml.dist in the project. mule starts PHPUnit the"
			. " way PHPUnit would start itself, so the configuration it will find is the one mule has"
			. " to digest — and there is not one.",
		);

		$this->composition()->assembled();
	}

	public function testTheDistributedPhpunitConfigurationWillDo(): void {
		unset($this->filesystem->files["/p/phpunit.xml"]);
		$this->filesystem->files["/p/phpunit.xml.dist"] = "<phpunit/>";

		self::assertSame(["src/A.php"], $this->composition()->assembled()->universe->sources());
	}

	/**
	 * **Built once on purpose.** Two reads of a file somebody may be editing can
	 * disagree, so the wiring happens in one pass and hands over what it made.
	 *
	 * @return void
	 */
	public function testTheWiringHappensInOnePass(): void {
		$assembly = $this->composition()->assembled();

		self::assertSame($assembly->universe, $assembly->universe);
	}

	/**
	 * One committed file beside one ignored directory: `git add .mule` must stage
	 * the store and nothing else.
	 *
	 * @return void
	 */
	public function testWhereThingsLive(): void {
		self::assertSame(".mule/verified.json", Composition::STORE);
		self::assertSame(".mule/cache", Composition::CACHE);
	}
}
