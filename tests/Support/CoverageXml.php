<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

/**
 * The two documents a PHPUnit `--coverage-xml` report is made of, as bytes.
 *
 * Written the way PHPUnit writes them — namespaced, the source root on the
 * project element, one `covered` element per covering test method — so that a
 * test can state a report without a disk anywhere near it.
 */
final class CoverageXml {

	/**
	 * The report's `index.xml`, which names what every file path is relative
	 * to.
	 *
	 * @param string $sourceRoot
	 * @return string
	 */
	public static function index(string $sourceRoot): string {
		return <<<XML
			<?xml version="1.0"?>
			<phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
				<project source="{$sourceRoot}"/>
			</phpunit>
			XML;
	}

	/**
	 * One per-file report.
	 *
	 * @param string $name The source file's own name, as PHPUnit writes it.
	 * @param string $path Its directory, relative to the source root, leading separator and all.
	 * @param list<string> $coveringTests Covering tests as `Class::method`, one line each.
	 * @return string
	 */
	public static function file(string $name, string $path, array $coveringTests): string {
		$lines = "";

		foreach($coveringTests as $number => $test) {
			$by = htmlspecialchars($test, ENT_XML1);
			$lines .= "<line nr=\"{$number}\"><covered by=\"{$by}\"/></line>";
		}

		return <<<XML
			<?xml version="1.0"?>
			<phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
				<file name="{$name}" path="{$path}"><coverage>{$lines}</coverage></file>
			</phpunit>
			XML;
	}
}
