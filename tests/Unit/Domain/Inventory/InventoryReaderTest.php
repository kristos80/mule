<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Domain\Inventory;

use Kristos80\Mule\Domain\Exception\DiscoveryException;
use Kristos80\Mule\Domain\Inventory\InventoryReader;
use Kristos80\Mule\Tests\Support\StubRelativePathResolver;
use PHPUnit\Framework\TestCase;
use function libxml_use_internal_errors;

final class InventoryReaderTest extends TestCase {

	private InventoryReader $reader;

	protected function setUp(): void {
		$this->reader = new InventoryReader(new StubRelativePathResolver());
	}

	public function testMapsEachTestClassToItsProjectRelativeFile(): void {
		$inventory = $this->reader->read($this->xml([
			["Acme\\Tests\\HubTest", "/project/tests/Unit/HubTest.php", ["testOne"]],
		]));

		self::assertSame(
			["Acme\\Tests\\HubTest" => "tests/Unit/HubTest.php"],
			$inventory->classes,
		);
	}

	public function testListsEachFileOnceEvenWhenTwoClassesShareIt(): void {
		$inventory = $this->reader->read($this->xml([
			["Acme\\Tests\\OneTest", "/project/tests/Unit/BothTest.php", ["testOne"]],
			["Acme\\Tests\\TwoTest", "/project/tests/Unit/BothTest.php", ["testTwo"]],
		]));

		self::assertSame(["tests/Unit/BothTest.php"], $inventory->files);
	}

	public function testOrdersFilesSoTwoRunsOfTheSameSuiteAgree(): void {
		$inventory = $this->reader->read($this->xml([
			["Acme\\Tests\\ZTest", "/project/tests/Unit/ZTest.php", ["testZ"]],
			["Acme\\Tests\\ATest", "/project/tests/Unit/ATest.php", ["testA"]],
		]));

		self::assertSame(["tests/Unit/ATest.php", "tests/Unit/ZTest.php"], $inventory->files);
	}

	/**
	 * A test class with no methods still has a file, and that file is the only
	 * record of a test which will execute no instrumented source. Dropping it
	 * here would make "covers nothing" indistinguishable from "never discovered".
	 */
	public function testKeepsATestClassThatDeclaresNoMethods(): void {
		$inventory = $this->reader->read($this->xml([
			["Acme\\Tests\\SilentTest", "/project/tests/Unit/SilentTest.php", []],
		]));

		self::assertSame(["tests/Unit/SilentTest.php"], $inventory->files);
		self::assertSame(
			["Acme\\Tests\\SilentTest" => "tests/Unit/SilentTest.php"],
			$inventory->classes,
		);
	}

	public function testReadsClassesDeclaredUnderPhpunitsOwnNamespace(): void {
		$xml = <<<XML
			<?xml version="1.0"?>
			<testSuite xmlns="https://xml.phpunit.de/testSuite">
			 <tests>
			  <testClass name="Acme\\Tests\\NamespacedTest" file="/project/tests/Unit/NamespacedTest.php">
			   <testMethod id="Acme\\Tests\\NamespacedTest::testOne" name="testOne"/>
			  </testClass>
			 </tests>
			</testSuite>
			XML;

		self::assertSame(["tests/Unit/NamespacedTest.php"], $this->reader->read($xml)->files);
	}

	public function testRefusesATestClassWithNoName(): void {
		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage("A test class in PHPUnit's inventory has no \"name\".");

		$this->reader->read(
			"<testSuite><tests><testClass file=\"/project/tests/Unit/NamelessTest.php\"/></tests></testSuite>",
		);
	}

	public function testRefusesATestClassWithNoFileAndNamesTheClass(): void {
		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage(
			"Test class \"Acme\\Tests\\FilelessTest\" in PHPUnit's inventory has no \"file\", so mule"
			. " cannot tell which test file to key on.",
		);

		$this->reader->read(
			"<testSuite><tests><testClass name=\"Acme\\Tests\\FilelessTest\"/></tests></testSuite>",
		);
	}

	public function testRefusesAnInventoryThatIsNotWellFormed(): void {
		$this->expectException(DiscoveryException::class);
		$this->expectExceptionMessage(
			"PHPUnit's test inventory is not well-formed XML, so mule cannot map test classes to"
			. " files.",
		);

		$this->reader->read("<testSuite><tests>");
	}

	public function testReadsAnEmptyInventoryAsASuiteWithNoTests(): void {
		$inventory = $this->reader->read("<testSuite><tests/></testSuite>");

		self::assertSame([], $inventory->classes);
		self::assertSame([], $inventory->files);
	}


	/**
	 * `libxml_use_internal_errors()` is global. A reader that switched it on to
	 * parse and then left it on would change how the rest of the process reports
	 * XML errors, which is invisible from this method's return value and so needs
	 * asserting directly.
	 */
	public function testRestoresLibxmlErrorHandlingAfterAFailedParse(): void {
		libxml_use_internal_errors(FALSE);

		try {
			$this->reader->read("<testSuite><tests>");
		} catch(DiscoveryException) {
			// The refusal is asserted elsewhere; this test is about the global.
		}

		self::assertFalse(libxml_use_internal_errors());
	}

	public function testRestoresLibxmlErrorHandlingAfterASuccessfulParse(): void {
		libxml_use_internal_errors(FALSE);

		$this->reader->read("<testSuite><tests/></testSuite>");

		self::assertFalse(libxml_use_internal_errors());
	}

	/**
	 * @param list<array{0: string, 1: string, 2: list<string>}> $classes
	 * @return string
	 */
	private function xml(array $classes): string {
		$body = "";

		foreach($classes as [$name, $file, $methods]) {
			$body .= "<testClass name=\"{$name}\" file=\"{$file}\">";

			foreach($methods as $method) {
				$body .= "<testMethod id=\"{$name}::{$method}\" name=\"{$method}\"/>";
			}

			$body .= "</testClass>";
		}

		return "<testSuite><tests>{$body}</tests></testSuite>";
	}
}
