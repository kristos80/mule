<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\ClassesInterface;
use Override;

/**
 * An autoloader a test spells out: a class is where the test says it is, and
 * nowhere otherwise.
 */
final readonly class StubClasses implements ClassesInterface {

	/**
	 * @param array<string, string> $files
	 */
	public function __construct(
		private array $files = [],
	) {}

	#[Override]
	public function fileOf(string $class): string|false {
		return $this->files[$class] ?? FALSE;
	}
}
