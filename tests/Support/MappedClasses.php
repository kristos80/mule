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
	 * @param list<string> $directories Absolute paths the project autoloads.
	 * @param array<string, string> $versions Package name to the version installed.
	 */
	public function __construct(
		private array $files = [],
		private array $directories = [],
		private array $versions = [],
	) {}

	/**
	 * @param string $class
	 * @return string|false
	 */
	public function fileOf(string $class): string|false {
		return $this->files[ltrim($class, "\\")] ?? FALSE;
	}

	/**
	 * @return list<string>
	 */
	public function directories(): array {
		return $this->directories;
	}

	/**
	 * A version a test can state, which is what makes an engine moving under a
	 * store something a test can arrange.
	 *
	 * @param string $package
	 * @return string|null
	 */
	public function versionOf(string $package): ?string {
		return $this->versions[$package] ?? NULL;
	}
}
