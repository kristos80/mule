<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\Shared\Config\ConfigurationInterface;

/**
 * A configuration that answers whatever the test needs it to.
 */
final readonly class StubConfiguration implements ConfigurationInterface {

	/**
	 * @param string $root
	 * @param string $sourceRoot
	 * @param list<string> $testRoots
	 * @param list<string> $supportRoots
	 * @param list<string> $excluded
	 * @param string $threads
	 * @param int $minCoverage
	 */
	public function __construct(
		private string $root = "/project",
		private string $sourceRoot = "src",
		private array $testRoots = ["tests/Unit"],
		private array $supportRoots = [],
		private array $excluded = [],
		private string $threads = "4",
		private int $minCoverage = 100,
	) {}

	public function root(): string {
		return $this->root;
	}

	public function sourceRoot(): string {
		return $this->sourceRoot;
	}

	/**
	 * @return list<string>
	 */
	public function testRoots(): array {
		return $this->testRoots;
	}

	/**
	 * @return list<string>
	 */
	public function supportRoots(): array {
		return $this->supportRoots;
	}

	/**
	 * @return list<string>
	 */
	public function excluded(): array {
		return $this->excluded;
	}

	public function threads(): string {
		return $this->threads;
	}

	public function minCoverage(): int {
		return $this->minCoverage;
	}
}
