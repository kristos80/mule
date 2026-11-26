<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Where the project keeps a class, asked of the project's own autoloader.
 *
 * **Half of every key is the test files covering a source file**, and a coverage
 * report names those tests as classes rather than as paths. So something has to
 * turn one into the other, and the only thing that knows the answer is the
 * loader the project built.
 *
 * `vendor/autoload.php` *returns* Composer's `ClassLoader`, and `findFile()`
 * resolves a class without loading it. An earlier implementation read
 * `composer.json` and reimplemented PSR-4 prefix resolution instead — 237 lines,
 * and 227 more testing the reimplementation. See `docs/decisions.md`.
 *
 * **It answers `FALSE` rather than throwing.** A covering test the autoloader
 * cannot place is a test whose edits would silently stop invalidating anything,
 * which is a failure worth a message — and the message belongs with the code
 * that knows which source file was being keyed, not here.
 */
interface ClassesInterface {

	/**
	 * The file a class lives in, or `FALSE` if the loader cannot place it.
	 *
	 * **The path is whatever Composer answers**, which is not canonical: a real
	 * answer looks like `…/vendor/composer/../../tests/Unit/Cache/KeyTest.php`.
	 * Resolving it is a decision and lives on the other side of this seam.
	 *
	 * @param string $class
	 * @return string|false
	 */
	public function fileOf(string $class): string|false;
}
