<?php
declare(strict_types=1);

namespace Kristos80\Mule\Coverage;

use Dom\XMLDocument;
use Kristos80\Mule\Failure;

/**
 * A PHPUnit `--coverage-xml` report, read for the two things mule needs: which
 * source files exist, and which test methods cover each of them.
 *
 * **Both halves of every key come out of a report the run already needs.** A
 * mutation engine cannot start without coverage, and each per-file report names
 * the test methods covering each line — that is how the engine decides which
 * tests to run per mutant. So the covering set is free, and the universe of
 * files is the report's own rather than a hand-written list: whatever the
 * project excludes from coverage is absent here too, and the two exclusion
 * lists cannot drift apart.
 *
 * **These are parsers over bytes.** Reading the files is the filesystem's job,
 * which is why nothing here takes a path.
 */
final readonly class Report {

	/**
	 * The directory every file report's path is relative to, read from
	 * `index.xml` rather than assumed.
	 *
	 * PHPUnit sets `<project source="…">` to the common ancestor of every
	 * covered file — `src` for a tree with files in several subdirectories, but
	 * `src/Cache` for a package whose covered files all sit in one.
	 * Reconstructing paths from a fixed prefix works only for the first shape.
	 *
	 * @param string $indexXml
	 * @return string
	 */
	public static function sourceRoot(string $indexXml): string {
		$document = XMLDocument::createFromString($indexXml, LIBXML_NOERROR);
		$project = $document->getElementsByTagNameNS("*", "project")->item(0)
			?? throw new Failure("The coverage report names no project.");

		return rtrim($project->getAttribute("source") ?? throw new Failure("The coverage report names no source root."), "/");
	}

	/**
	 * The per-file reports among a coverage directory's entries, sorted.
	 *
	 * **The order is imposed here**, because the filesystem's own is not
	 * something a test can choose — so an ordering mule needs has to be one a
	 * missing assertion would be caught by. Sorting is also what reindexes the
	 * filtered list, so there is nothing else to do to it.
	 *
	 * @param list<string> $entries Every path under the coverage directory, in any order.
	 * @return list<string>
	 */
	public static function reports(array $entries): array {
		$reports = array_filter(
			$entries,
			static fn(string $entry): bool => str_ends_with($entry, ".php.xml"),
		);

		sort($reports);

		return $reports;
	}
}
