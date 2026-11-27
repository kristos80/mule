<?php
declare(strict_types=1);

namespace Kristos80\Mule\Shared\Config;

/**
 * Everything mule needs to know about the project it is gating.
 *
 * **This interface exists so that no class in `src/` holds its own
 * configuration.** A service that took the project root as a constructor string
 * would be a service carrying data, and a service that read `getenv()` would be
 * one that cannot be tested without an environment. Both were how earlier
 * attempts at this tool were written. Every value here arrives from one
 * implementation at the I/O boundary and is injected inward as a contract.
 *
 * **Accessors rather than a single method, because this is a contract over data
 * and not a domain service.** The one-public-method rule applies to classes that
 * do something; this one only answers questions, and splitting seven answers
 * into seven interfaces would put the same object in every constructor seven
 * times.
 */
interface ConfigurationInterface {

	/**
	 * The absolute project root, without a trailing separator.
	 *
	 * **The only absolute path in the configuration**, and it never reaches a
	 * verification identity — P3 requires a key that hits in CI as well as on the
	 * machine that banked it.
	 *
	 * @return string
	 */
	public function root(): string;

	/**
	 * Where the gated source lives, project-relative.
	 *
	 * @return string
	 */
	public function sourceRoot(): string;

	/**
	 * Where the unit tests live, project-relative.
	 *
	 * @return list<string>
	 */
	public function testRoots(): array;

	/**
	 * Extra roots holding test-support code and fixture data, project-relative.
	 *
	 * **These exist because a coverage report cannot name them.** Per-test
	 * attribution covers the source root only, so a factory that builds fixtures
	 * is invisible to it. Anything under a test root is picked up without being
	 * declared; this is for support kept somewhere else.
	 *
	 * @return list<string>
	 */
	public function supportRoots(): array;

	/**
	 * Source files that are neither mutated nor required to carry coverage.
	 *
	 * @return list<string>
	 */
	public function excluded(): array;

	/**
	 * How many parallel workers the engine may use.
	 *
	 * @return string
	 */
	public function threads(): string;

	/**
	 * The line-coverage percentage below which the run fails.
	 *
	 * @return int
	 */
	public function minCoverage(): int;
}
