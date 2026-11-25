<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;

/**
 * The project's own Composer autoloader, asked where a class lives.
 *
 * `findFile()` consults the classmap and then every registered prefix, and
 * **does not load the class** — so mule reads the project's real autoload
 * configuration without executing a line of its test suite.
 *
 * The loader is the one `bin/mule` resolved from the project being gated,
 * rather than whichever autoloader happened to boot mule: installed as a dev
 * dependency those are the same file, and run from anywhere else they are not.
 */
final readonly class ComposerClasses implements ClassesInterface {

	/**
	 * @param ClassLoader $loader
	 */
	public function __construct(
		private ClassLoader $loader,
	) {}

	/**
	 * @param string $class
	 * @return string|false
	 */
	public function fileOf(string $class): string|false {
		return $this->loader->findFile($class);
	}

	/**
	 * @return list<string>
	 */
	public function directories(): array {
		return array_merge(...array_values($this->loader->getPrefixesPsr4()));
	}

	/**
	 * @param string $package
	 * @return string|null
	 */
	public function versionOf(string $package): ?string {
		return InstalledVersions::getPrettyVersion($package);
	}
}
