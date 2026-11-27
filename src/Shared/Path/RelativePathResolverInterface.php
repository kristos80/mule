<?php
declare(strict_types=1);

namespace Kristos80\Mule\Shared\Path;

/**
 * Turns an absolute path into the project-relative form every identity uses.
 */
interface RelativePathResolverInterface {

	/**
	 * @param string $absolute
	 * @return string
	 */
	public function resolve(string $absolute): string;
}
