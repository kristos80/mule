<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Inventory;

/**
 * Which file each test class lives in, as PHPUnit reports it about itself.
 *
 * **Anemic on purpose.** It answers no questions and resolves nothing; the
 * lookup that needs `classes` does it where the miss can be explained, because
 * a class the inventory has never heard of is a diagnostic about two runs
 * disagreeing and not a value this object could sensibly return.
 *
 * **`files` carries test files that cover nothing.** The inventory lists every
 * class PHPUnit would run, including those that go on to execute no instrumented
 * source — seven of them on the reference application. Those files never appear
 * in a coverage report, so this is the only place they exist, and it is what
 * lets "covers nothing" be told apart from "has never been discovered".
 */
final readonly class TestInventory {

	/**
	 * @param array<string, string> $classes Test class FQN to project-relative test file.
	 * @param list<string> $files Every distinct project-relative test file, sorted.
	 */
	public function __construct(
		public array $classes,
		public array $files,
	) {}
}
