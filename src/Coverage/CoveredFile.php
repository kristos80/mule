<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Dom\Element;
use Dom\XMLDocument;
use Kristos80\Mule\Failure;

/**
 * One source file as a coverage report describes it: where it is, which tests
 * touched it, and how much of it they reached.
 *
 * **How much they reached is read from the file's own totals, and the ranges
 * from its methods.** The two are different kinds of thing: the totals decide
 * whether mule may call the file proven, and the ranges only say where to look
 * when it may not. A report whose methods do not account for every executable
 * line still gives a correct decision and a less complete explanation, which is
 * the right way round.
 */
final readonly class CoveredFile {

	/**
	 * @param string $path The absolute path of the source file.
	 * @param list<string> $coveringTests Every covering test, as `Class::method`.
	 * @param int $executable How many of its lines can be executed at all.
	 * @param int $executed How many of those the tests reached.
	 * @param list<Unreached> $unreached Where the gap is, when there is one.
	 */
	private function __construct(
		public string $path,
		public array $coveringTests,
		public int $executable,
		public int $executed,
		public array $unreached,
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
			?? throw new Failure("The coverage report {$subject} names no file.");
		$path = rtrim(self::attribute($file, "path", $subject), "/");
		$lines = $document->getElementsByTagNameNS("*", "lines")->item(0)
			?? throw new Failure("The coverage report {$subject} gives the file no totals.");
		$tests = [];

		foreach($document->getElementsByTagNameNS("*", "covered") as $covered) {
			$tests[] = self::attribute($covered, "by", $subject);
		}

		return new self(
			"{$sourceRoot}{$path}/" . self::attribute($file, "name", $subject),
			array_values(array_unique($tests)),
			self::number($lines, "executable", $subject),
			self::number($lines, "executed", $subject),
			self::unreachedIn($document, $subject),
		);
	}

	/**
	 * Whether every line of it ran.
	 *
	 * @return bool
	 */
	public function isFullyCovered(): bool {
		return $this->executed === $this->executable;
	}

	/**
	 * Every method and function the tests did not reach all of, in the order
	 * the report lists them.
	 *
	 * @param XMLDocument $document
	 * @param string $subject
	 * @return list<Unreached>
	 */
	private static function unreachedIn(XMLDocument $document, string $subject): array {
		$unreached = [];

		foreach(["method", "function"] as $kind) {
			foreach($document->getElementsByTagNameNS("*", $kind) as $element) {
				$missed = self::number($element, "executable", $subject)
					- self::number($element, "executed", $subject);

				if($missed > 0) {
					$unreached[] = new Unreached(
						self::attribute($element, "name", $subject),
						self::number($element, "start", $subject),
						self::number($element, "end", $subject),
						$missed,
					);
				}
			}
		}

		return $unreached;
	}

	/**
	 * A counted attribute.
	 *
	 * **The conversion is the return value of a method typed `int`**, so that
	 * dropping it is a type error rather than nothing at all: PHP would do the
	 * same arithmetic on the string, which makes a cast written inline an
	 * equivalent mutant and the first excuse for a suppression.
	 *
	 * @param Element $element
	 * @param string $name
	 * @param string $subject
	 * @return int
	 */
	private static function number(Element $element, string $name, string $subject): int {
		return (int) self::attribute($element, $name, $subject);
	}

	/**
	 * An attribute the report has to carry.
	 *
	 * One place asks, so one assertion proves that a report missing any of them
	 * is refused rather than read as something else.
	 *
	 * @param Element $element
	 * @param string $name
	 * @param string $subject
	 * @return string
	 */
	private static function attribute(Element $element, string $name, string $subject): string {
		return $element->getAttribute($name)
			?? throw new Failure("The coverage report {$subject} has a {$element->localName} with no {$name}.");
	}
}
