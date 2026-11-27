<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Inventory;

use DOMDocument;
use DOMElement;
use Kristos80\Mule\Domain\Exception\DiscoveryException;
use Kristos80\Mule\Shared\Path\RelativePathResolverInterface;
use function libxml_use_internal_errors;
use function sort;

/**
 * PHPUnit's `--list-tests-xml` inventory, as a relation mule can key on.
 *
 * **This exists because the coverage report names tests, not files.** Every
 * record in `tests.jsonl` is a `Class::method` identifier, and the unit mule
 * keys on is the test *file*, so something has to map one to the other. Asking
 * PHPUnit is what lets v6 own no class resolver at all: no Composer autoloader,
 * no PSR-4 arithmetic, no parser. Measured on a 334-file suite: 334 files
 * resolved, none unresolved.
 *
 * **Reading the inventory is a second process, and that is not an oversight.**
 * Measured at PHPUnit 13.4.8: `--list-tests-xml` is list-and-exit. Handed both
 * flags at once it prints "Wrote list of tests that would have been run", writes
 * the inventory, creates no coverage directory and runs no test. Inventory and
 * execution therefore cannot be one command, and code written as though they
 * could would be parsing a coverage report that was never produced.
 */
final readonly class InventoryReader implements InventoryReaderInterface {

	/**
	 * The element PHPUnit describes each test class with.
	 */
	private const string ELEMENT = "testClass";

	/**
	 * @param RelativePathResolverInterface $paths
	 */
	public function __construct(
		private RelativePathResolverInterface $paths,
	) {}

	/**
	 * @param string $xml The contents of a `--list-tests-xml` file.
	 * @return TestInventory
	 * @throws DiscoveryException when the document is not well-formed, or a listed class has no name or file
	 */
	public function read(string $xml): TestInventory {
		$classes = [];
		$files = [];

		foreach($this->elements($xml) as $element) {
			$name = $element->getAttribute("name");
			$file = $this->fileOf($element, $name);

			$classes[$name] = $file;
			// Keyed by the path and holding the path: deduplication and a `list<string>`
			// out of one structure, with no value that nothing reads.
			$files[$file] = $file;
		}

		// `sort()` reindexes, so there is nothing left for `array_values()` to do.
		$distinct = $files;
		sort($distinct);

		return new TestInventory($classes, $distinct);
	}

	/**
	 * @param DOMElement $element
	 * @param string $name
	 * @return string
	 * @throws DiscoveryException when the class has no name, or no file to resolve
	 */
	private function fileOf(DOMElement $element, string $name): string {
		if($name === "") {
			throw new DiscoveryException("A test class in PHPUnit's inventory has no \"name\".");
		}

		$file = $element->getAttribute("file");

		if($file === "") {
			throw new DiscoveryException(
				"Test class \"{$name}\" in PHPUnit's inventory has no \"file\", so mule cannot tell"
				. " which test file to key on.",
			);
		}

		return $this->paths->resolve($file);
	}

	/**
	 * **Matched by local name, in any namespace.** The inventory is served in
	 * `https://xml.phpunit.de/testSuite`; a reader that matched the unprefixed tag
	 * would keep working until PHPUnit prefixed its own elements and then find
	 * nothing, which reads as a suite with no tests rather than as a parse that
	 * failed.
	 *
	 * @param string $xml
	 * @return list<DOMElement>
	 * @throws DiscoveryException when the document is not well-formed
	 */
	private function elements(string $xml): array {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(TRUE);
		$parsed = $document->loadXML($xml);
		libxml_use_internal_errors($previous);

		if(!$parsed) {
			throw new DiscoveryException(
				"PHPUnit's test inventory is not well-formed XML, so mule cannot map test classes"
				. " to files.",
			);
		}

		$elements = [];

		foreach($document->getElementsByTagNameNS("*", self::ELEMENT) as $element) {
			$elements[] = $element;
		}

		return $elements;
	}
}
