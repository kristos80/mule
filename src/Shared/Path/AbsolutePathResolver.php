<?php
declare(strict_types=1);

namespace Kristos80\Mule\Shared\Path;

use Kristos80\Mule\Shared\Config\ConfigurationInterface;
use function rtrim;

/**
 * The opposite direction, for the processes mule starts.
 *
 * **A separate class rather than a second method on the relative resolver**, so
 * that a caller which only ever needs one direction cannot reach the other. The
 * two travel in opposite directions across the same boundary and share nothing
 * but the root, which they both ask the configuration for.
 */
final readonly class AbsolutePathResolver implements AbsolutePathResolverInterface {

	/**
	 * @param ConfigurationInterface $configuration
	 */
	public function __construct(
		private ConfigurationInterface $configuration,
	) {}

	/**
	 * @param string $relative An empty string resolves to the project root itself.
	 * @return string
	 */
	public function resolve(string $relative): string {
		$root = rtrim($this->configuration->root(), "/");

		return $relative === "" ? $root : "{$root}/{$relative}";
	}
}
