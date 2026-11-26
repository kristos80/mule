<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Composer\InstalledVersions;
use Kristos80\Mule\Failure;
use Override;
use function extension_loaded;
use function php_ini_loaded_file;
use function phpversion;
use const PHP_BINARY;
use const PHP_VERSION;

/**
 * The machine, as PHP describes it.
 *
 * **One constant or one function per method.** Nothing here decides anything, so
 * it is excluded from coverage and from mutation like the other wrappers — named
 * in both `phpunit.xml` and `infection.json5` so the two lists stay visibly in
 * step.
 */
final readonly class PhpEnvironment implements EnvironmentInterface {

	/**
	 * The drivers php-code-coverage can use, in the order it prefers them.
	 */
	private const array DRIVERS = ["pcov", "xdebug"];

	/**
	 * mule's own package name, as Composer knows it.
	 */
	private const string PACKAGE = "kristos80/mule";

	#[Override]
	public function interpreter(): string {
		return PHP_BINARY;
	}

	#[Override]
	public function ini(): string {
		return php_ini_loaded_file() ?: "";
	}

	#[Override]
	public function php(): string {
		return PHP_VERSION;
	}

	#[Override]
	public function driver(): string {
		foreach(self::DRIVERS as $extension) {
			if(extension_loaded($extension)) {
				return $extension . " " . (phpversion($extension) ?: "unknown");
			}
		}

		return "none";
	}

	/**
	 * **The root package first, because mule gates itself.** Asking Composer for
	 * an installed `kristos80/mule` from inside a checkout of mule throws rather
	 * than answering, so the only run that would crash here is mule's own.
	 */
	#[Override]
	public function mule(): string {
		$root = InstalledVersions::getRootPackage();

		return $root["name"] === self::PACKAGE
			? $root["pretty_version"]
			: (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? "dev");
	}

	#[Override]
	public function directory(): string {
		$directory = getcwd();

		if($directory === FALSE) {
			throw new Failure("mule cannot tell which directory it was started in.");
		}

		return $directory;
	}
}
