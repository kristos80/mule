<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Composer\Autoload\ClassLoader;
use Override;

/**
 * Composer's `ClassLoader`, asked where a class lives.
 *
 * One statement, so there is nothing to unit test and nothing a mutant could
 * change that an assertion would notice. Excluded from coverage and mutation
 * alongside the other wrappers, and exercised by the integration suite.
 *
 * **Which loader is handed in matters.** mule's composition root requires its
 * own autoloader first so mule can boot, and the project's last, so the loader
 * that answers is the one that knows where the project's tests live. That is the
 * same file when mule is a dev dependency and a different one when mule is run
 * as a tool of its own.
 */
final readonly class ComposerClasses implements ClassesInterface {

	/**
	 * @param ClassLoader $loader The loader `vendor/autoload.php` returned.
	 */
	public function __construct(private ClassLoader $loader) {}

	#[Override]
	public function fileOf(string $class): string|false {
		return $this->loader->findFile($class);
	}
}
