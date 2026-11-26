<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * The things about the machine a run happens on that a verdict depends on.
 *
 * **All of it is in the cache key**, so all of it has to be readable by a test
 * that wants to prove the key moves when it should (`Cache\Toolchain`). Reading
 * `PHP_VERSION` where it is used would make "a verdict proven under another PHP"
 * a case nothing can set up.
 */
interface EnvironmentInterface {

	/**
	 * The PHP binary to start children with.
	 *
	 * @return string
	 */
	public function interpreter(): string;

	/**
	 * The parent's loaded ini file, or an empty string if it has none.
	 *
	 * **Forwarded to children, because a child does not inherit a coverage driver
	 * that arrived on the command line** (`Runner\Commands`).
	 *
	 * @return string
	 */
	public function ini(): string;

	/**
	 * @return string
	 */
	public function php(): string;

	/**
	 * The coverage driver and its version, or a word saying there is none.
	 *
	 * @return string
	 */
	public function driver(): string;

	/**
	 * mule's own version.
	 *
	 * **In the key, so an upgrade invalidates the store.** A release that changes
	 * what mule asks the engine, or how it reads the answer, has changed what a
	 * verdict means — and the alternative is a store carrying claims proven by a
	 * version whose guards were weaker.
	 *
	 * @return string
	 */
	public function mule(): string;

	/**
	 * Where mule was started.
	 *
	 * @return string
	 */
	public function directory(): string;
}
