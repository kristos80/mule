<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Inventory;

use Kristos80\Mule\Domain\Exception\DiscoveryException;

/**
 * Reads PHPUnit's own test inventory.
 */
interface InventoryReaderInterface {

	/**
	 * @param string $xml The contents of a `--list-tests-xml` file.
	 * @return TestInventory
	 * @throws DiscoveryException when the document is not well-formed, or a listed class has no name or file
	 */
	public function read(string $xml): TestInventory;
}
