<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\Shared\Path\RelativePathResolverInterface;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Strips one fixed prefix, so a test can tell a resolved path from a raw one
 * without depending on how the real resolver reads its configuration.
 */
final readonly class StubRelativePathResolver implements RelativePathResolverInterface {

	/**
	 * @param string $prefix
	 */
	public function __construct(
		private string $prefix = "/project/",
	) {}

	/**
	 * @param string $absolute
	 * @return string
	 */
	public function resolve(string $absolute): string {
		return str_starts_with($absolute, $this->prefix)
			? substr($absolute, strlen($this->prefix))
			: $absolute;
	}
}
