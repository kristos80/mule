<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

/**
 * One invocation's scratch space, and the two paths inside it that a coverage
 * run writes and the engine reads.
 */
final readonly class Run {

	/**
	 * @param string $directory
	 */
	public function __construct(
		public string $directory,
	) {}

	/**
	 * Where the test runner writes its PHPUnit XML coverage report.
	 *
	 * @return string
	 */
	public function coverageXml(): string {
		return "{$this->directory}/coverage-xml";
	}

	/**
	 * Where it writes its JUnit log, which an engine needs to time the tests it
	 * runs per mutant.
	 *
	 * @return string
	 */
	public function junit(): string {
		return "{$this->directory}/junit.xml";
	}
}
