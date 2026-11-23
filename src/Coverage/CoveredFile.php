<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Dom\Element;
use Dom\XMLDocument;
use RuntimeException;

/**
 * One source file as a coverage report describes it: where it is, and which
 * tests touched it.
 */
final readonly class CoveredFile {

	/**
	 * @param string $path The absolute path of the source file.
	 * @param list<string> $coveringTests Every covering test, as `Class::method`.
	 */
	private function __construct(
		public string $path,
		public array $coveringTests,
	) {}

	/**
	 * One per-file report, parsed.
	 *
	 * PHPUnit writes these under its own XML namespace, so every lookup here is
	 * namespace-agnostic. Malformed bytes throw from the parser itself, which is
	 * why nothing here checks for them.
	 *
	 * @param string $xml
	 * @param string $sourceRoot What the report's own paths are relative to.
	 * @param string $subject The report's path, for the message.
	 * @return self
	 */
	public static function of(string $xml, string $sourceRoot, string $subject): self {
		$document = XMLDocument::createFromString($xml, LIBXML_NOERROR);
		$file = $document->getElementsByTagNameNS("*", "file")->item(0)
			?? throw new RuntimeException("The coverage report {$subject} names no file.");
		$path = rtrim(self::attribute($file, "path", $subject), "/");
		$tests = [];

		foreach($document->getElementsByTagNameNS("*", "covered") as $covered) {
			$tests[] = self::attribute($covered, "by", $subject);
		}

		return new self(
			"{$sourceRoot}{$path}/" . self::attribute($file, "name", $subject),
			array_values(array_unique($tests)),
		);
	}

	/**
	 * An attribute the report has to carry.
	 *
	 * One place asks, so one assertion proves that a report missing any of the
	 * three is refused rather than keyed as something else.
	 *
	 * @param Element $element
	 * @param string $name
	 * @param string $subject
	 * @return string
	 */
	private static function attribute(Element $element, string $name, string $subject): string {
		return $element->getAttribute($name)
			?? throw new RuntimeException("The coverage report {$subject} has a {$element->localName} with no {$name}.");
	}
}
