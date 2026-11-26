<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Dom\Element;
use Dom\XMLDocument;
use Dom\XPath;
use Kristos80\Mule\Failure;
use XMLWriter;
use const LIBXML_NOERROR;

/**
 * How long each test class takes, and where it lives.
 *
 * **This is the artifact that must never be merged.** Infection reads two
 * attributes out of `junit.xml` — `file` and `time`, off the class-level
 * `<testsuite>` node — and uses the time to decide whether a mutant is worth
 * running at all. Concatenating shard JUnits produces a structurally valid file
 * in which every class carries its own cold-process total, inflating the numbers
 * 1.89× at the median, and Infection then **silently discards** mutants that look
 * slow: absent from every detail array in the JSON log, and dropped from MSI's
 * denominator. Measured, 40 of 1,174 mutants vanished while MSI moved 95.32 →
 * 95.15. See `docs/keying.md` M12 and M13.
 *
 * So timings come from one monolithic reference capture, kept as their own keyed
 * artifact, while attribution comes from the merged shards (M2).
 *
 * **Per class, not per test.** `JUnitTestExecutionInfoAdder` reduces a covering
 * test to the part before the first `:` and looks that up, and
 * `TestTotalTimeCalculator` then sums one time per distinct class. So a mutant
 * covered by forty methods of one class costs that class's total once — and a hub
 * file covered by sixty-five classes sums sixty-five totals, which is why
 * inflation bites hardest exactly where the cache is least use.
 */
final readonly class Timings {

	/**
	 * Keyed by test class.
	 *
	 * @var array<non-empty-string, array{file: string, time: float}>
	 */
	public array $classes;

	/**
	 * **Canonical on the way in, once.** The map is persisted and the report is
	 * rendered from it, so an order that depended on how the classes were
	 * discovered would mean the same timings giving different bytes. Sorting here
	 * rather than at each use leaves one place to get it right and no second sort
	 * to disagree with.
	 *
	 * @param array<non-empty-string, array{file: string, time: float}> $classes
	 */
	private function __construct(array $classes) {
		ksort($classes);

		$this->classes = $classes;
	}

	/**
	 * Reads a reference capture's JUnit report.
	 *
	 * Bytes in, never a path: what mule does with a report is a decision, and
	 * where the report came from is the filesystem's business.
	 *
	 * @param string $junit The contents of a `--log-junit` file.
	 * @return self
	 */
	public static function from(string $junit): self {
		$xPath = new XPath(XMLDocument::createFromString($junit, LIBXML_NOERROR));
		$classes = [];

		foreach($xPath->query("//testsuite") as $node) {
			if($node instanceof Element) {
				$classes = self::read($node, $classes);
			}
		}

		if($classes === []) {
			throw new Failure(
				"A JUnit report named no test classes. Infection reads the time and the file"
				. " off the class-level <testsuite> nodes, and without them every mutant looks"
				. " slow enough to skip.",
			);
		}

		return new self($classes);
	}

	/**
	 * @param array<non-empty-string, array{file: string, time: float}> $classes
	 * @return self
	 */
	public static function of(array $classes): self {
		return new self($classes);
	}

	/**
	 * The report Infection is handed: one node per test class, carrying the two
	 * attributes it reads and nothing else.
	 *
	 * The lookup is `//testsuite[@name="…"][1]`, which takes the first match —
	 * with one node per class there is only ever one, which is the difference
	 * between this and a concatenation of shard reports. The order is the
	 * constructor's business.
	 *
	 * @return string
	 */
	public function junit(): string {
		$writer = new XMLWriter();
		$writer->openMemory();
		$writer->startDocument("1.0", "UTF-8");
		$writer->startElement("testsuites");

		foreach($this->classes as $class => $timing) {
			$writer->startElement("testsuite");
			$writer->writeAttribute("name", $class);
			$writer->writeAttribute("file", $timing["file"]);
			$writer->writeAttribute("time", sprintf("%.6F", $timing["time"]));
			$writer->endElement();
		}

		// `endDocument()` closes whatever is still open, so closing the root here
		// would be a call with no output behind it — and the gate said so.
		$writer->endDocument();

		return $writer->outputMemory();
	}

	/**
	 * Of the test classes named, those this artifact cannot time.
	 *
	 * **G4 is not advice.** `JUnitTestFileDataProvider::getTestFileInfo()` throws
	 * `TestNotFound` for a class it cannot find, so a class present in the
	 * attribution and absent here stops the run — and defaulting it to zero would
	 * be worse than stopping, because zero is the one time that can never look
	 * slow and so hides the gap it came from.
	 *
	 * @param list<non-empty-string> $classes
	 * @return list<non-empty-string>
	 */
	public function missing(array $classes): array {
		$missing = [];

		foreach($classes as $class) {
			if(!isset($this->classes[$class])) {
				$missing[] = $class;
			}
		}

		return $missing;
	}

	/**
	 * One `<testsuite>` node, added if it describes a test class.
	 *
	 * **A suite with no `file` is not a class**, it is the root suite PHPUnit
	 * wraps everything in — and timing that would add the whole run's duration to
	 * every mutant anything covers. So it is passed over rather than refused.
	 *
	 * The two attributes that *are* there get a refusal each, because the
	 * alternative to a refusal is a default, and both defaults are dangerous: an
	 * unnamed class can never be looked up, and a missing time reads as `0.0`,
	 * which is the one value that can never look slow and so hides the gap it
	 * came from.
	 *
	 * @param Element $node
	 * @param array<non-empty-string, array{file: string, time: float}> $classes
	 * @return array<non-empty-string, array{file: string, time: float}>
	 */
	private static function read(Element $node, array $classes): array {
		$file = $node->getAttribute("file");

		if($file === NULL || $file === "") {
			return $classes;
		}

		$name = $node->getAttribute("name");

		if($name === NULL || $name === "") {
			throw new Failure("A JUnit report has a <testsuite> with an empty name.");
		}

		$time = $node->getAttribute("time");

		if($time === NULL) {
			throw new Failure("The JUnit report gives no time for the test class \"{$name}\".");
		}

		$classes[$name] = ["file" => $file, "time" => (float) $time];

		return $classes;
	}
}
