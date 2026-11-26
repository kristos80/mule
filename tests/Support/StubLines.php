<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\LinesInterface;
use Override;

/**
 * Executable lines a test states outright, rather than parsing a file to find
 * out — which is what makes "the report names a line the file does not have"
 * something a test can arrange.
 */
final readonly class StubLines implements LinesInterface {

	/**
	 * @param array<string, list<int>> $lines
	 */
	public function __construct(
		private array $lines = [],
	) {}

	#[Override]
	public function executableIn(string $file): array {
		return $this->lines[$file] ?? [];
	}
}
