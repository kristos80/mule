<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Where a class lives.
 *
 * **Half of every cache key is a digest over the test files covering a source
 * file, and a coverage report names those tests as classes** —
 * `Acme\Tests\Cache\KeyTest::testOne`. Something has to turn that into
 * `tests/Cache/KeyTest.php`.
 *
 * Mule does not work that out itself. The project has already declared the
 * mapping to Composer, and `vendor/autoload.php` returns the very object that
 * answers it, so the answer here cannot drift from the one the autoloader uses
 * — and a project autoloading its tests with `classmap` or `psr-0` is answered
 * as correctly as a `psr-4` one.
 */
interface ClassesInterface {

	/**
	 * The file a class lives in, or FALSE when the project's autoloader cannot
	 * place it.
	 *
	 * **The failure is returned rather than thrown**, because it is the one I/O
	 * answer mule has to decide about: a class nobody can place is a test whose
	 * edits would silently stop invalidating anything, and the message that
	 * says so belongs with the code that knows which source file was being
	 * keyed.
	 *
	 * @param string $class A fully qualified class name, with or without a leading separator.
	 * @return string|false
	 */
	public function fileOf(string $class): string|false;
}
