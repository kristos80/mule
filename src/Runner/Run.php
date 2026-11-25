<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

/**
 * One invocation's scratch space, and the paths inside it: the two a coverage
 * run writes and the engine reads, and the one mule writes for the engine.
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

	/**
	 * Where mule writes the configuration it hands the engine.
	 *
	 * **Here rather than in the project**, so a crash leaves nothing behind in
	 * a repository and two runs at once cannot read each other's. The engine
	 * resolves a configuration's relative paths against its directory, which is
	 * this one — so everything written into it is absolute.
	 *
	 * @return string
	 */
	public function engineJson(): string {
		return "{$this->directory}/infection.json";
	}
}
