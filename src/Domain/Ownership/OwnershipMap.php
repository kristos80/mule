<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Ownership;

/**
 * Which test files execute which source files, as the coverage driver observed
 * it.
 *
 * **This is the whole of v6's discovery.** `tests.jsonl` records, per test, the
 * source files that test executed; the inventory turns each test's class into a
 * file; and the relation between the two is what every verification identity
 * rests on. There is no closure engine, no dependency graph, and no second
 * source of truth, because the driver already watched the answer happen.
 *
 * **What this relation does not see, and must never be read as seeing.** It
 * attributes *source* files to tests and nothing more. The coverage report's
 * source root is the project's source directory, so no path under the test roots
 * ever appears in it: this says nothing about which test-support files a test
 * depends on. Support code is keyed by a declared boundary precisely because
 * this relation cannot answer that question, and treating it as though it could
 * would bank stale proofs after a fixture change.
 *
 * **Both directions are stored rather than derived.** Inverting a 404-source
 * relation on every lookup would be the same work repeated per file, and the
 * reader has to walk it once anyway to build either side.
 */
final readonly class OwnershipMap {

	/**
	 * @param array<string, list<string>> $owns Project-relative test file to the source files it executes.
	 * @param array<string, list<string>> $owners Project-relative source file to the test files executing it.
	 */
	public function __construct(
		public array $owns,
		public array $owners,
	) {}
}
