<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Ownership;

/**
 * Resolves the source files whose behaviour can change a given file's verdict.
 */
interface CoexecutionResolverInterface {

	/**
	 * @param OwnershipMap $ownership
	 * @param string $source Project-relative.
	 * @return list<string> Sorted, and never containing `$source` itself.
	 */
	public function resolve(OwnershipMap $ownership, string $source): array;
}
