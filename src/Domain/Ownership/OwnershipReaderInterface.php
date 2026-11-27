<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Ownership;

use Kristos80\Mule\Domain\Exception\DiscoveryException;
use Kristos80\Mule\Domain\Inventory\TestInventory;

/**
 * Reads which test files execute which source files.
 */
interface OwnershipReaderInterface {

	/**
	 * @param string $jsonl The contents of the report's `tests.jsonl`.
	 * @param TestInventory $inventory
	 * @param string $sourceRoot Project-relative, as the report's own metadata gives it.
	 * @return OwnershipMap
	 * @throws DiscoveryException when a record is malformed, or credits a class the inventory does not list
	 */
	public function read(string $jsonl, TestInventory $inventory, string $sourceRoot): OwnershipMap;
}
