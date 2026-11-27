<?php
declare(strict_types=1);

namespace Kristos80\Mule\Shared\Path;

/**
 * Turns a project-relative path into one a child process can use.
 */
interface AbsolutePathResolverInterface {

	/**
	 * @param string $relative
	 * @return string
	 */
	public function resolve(string $relative): string;
}
