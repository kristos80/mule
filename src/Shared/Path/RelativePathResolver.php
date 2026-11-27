<?php
declare(strict_types=1);

namespace Kristos80\Mule\Shared\Path;

use Kristos80\Mule\Shared\Config\ConfigurationInterface;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * **Why every path in a key goes through here.** P3 says a banked verdict must
 * hit in CI as well as on the machine that banked it, so no absolute path may
 * reach a verification identity. PHPUnit reports absolute paths in its test
 * inventory, and the project root is configuration — which makes this the one
 * seam where the two meet.
 */
final readonly class RelativePathResolver implements RelativePathResolverInterface {

	/**
	 * @param ConfigurationInterface $configuration
	 */
	public function __construct(
		private ConfigurationInterface $configuration,
	) {}

	/**
	 * **A path outside the root comes back unchanged rather than mangled.** The
	 * callers that matter only ever name files inside the project, so a path that
	 * is not under the root is a fact worth preserving for whoever reads the
	 * diagnostic — truncating it to something that merely looks relative would
	 * hide which file was meant.
	 *
	 * @param string $absolute
	 * @return string
	 */
	public function resolve(string $absolute): string {
		$prefix = rtrim($this->configuration->root(), "/") . "/";

		return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
	}
}
