<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * What a file's full score was proven against: the file itself, and the tests
 * covering it.
 *
 * **The second half is the point.** A file's mutation score is not a property
 * of the file; it is a property of the file *and the tests that cover it*.
 * Weaken an assertion in one test and a mutant in an untouched class starts
 * surviving — so a cache keyed on the source digest alone would answer
 * "verified" in exactly the case the gate exists to catch.
 */
final readonly class Verdict {

	/**
	 * @param string $source The digest of the source file itself.
	 * @param string $tests One digest over every test file covering it.
	 */
	public function __construct(
		public string $source,
		public string $tests,
	) {}

	/**
	 * Whether two verdicts describe the same source and the same covering
	 * tests.
	 *
	 * @param self $other
	 * @return bool
	 */
	public function matches(self $other): bool {
		return $this->source === $other->source && $this->tests === $other->tests;
	}
}
