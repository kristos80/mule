<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\ClassesInterface;

/**
 * An autoloader a test writes out: class name to absolute path, and FALSE for
 * anything it was not told about.
 */
final class MappedClasses implements ClassesInterface {

	/**
	 * @param array<string, string> $files Class name to absolute path.
	 */
	public function __construct(
		private array $files = [],
	) {}

	/**
	 * @param string $class
	 * @return string|false
	 */
	public function fileOf(string $class): string|false {
		return $this->files[ltrim($class, "\\")] ?? FALSE;
	}
}
