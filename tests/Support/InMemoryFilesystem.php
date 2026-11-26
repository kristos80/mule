<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\Failure;
use Override;

/**
 * The filesystem as an array.
 *
 * The unit suite drives the whole program through this rather than through a
 * temporary directory, so no test arranges a disk and none of them can leave one
 * behind. What the real wrapper does with the same calls is the integration
 * suite's business.
 */
final class InMemoryFilesystem implements FilesystemInterface {

	/**
	 * @param array<string, string> $files Path to contents.
	 * @param array<string, non-empty-string> $digests Path to digest; a file with no entry hashes its contents.
	 */
	public function __construct(
		public array $files = [],
		private array $digests = [],
	) {}

	#[Override]
	public function read(string $path): string {
		if(!array_key_exists($path, $this->files)) {
			throw new Failure("There is no file at \"{$path}\".");
		}

		return $this->files[$path];
	}

	#[Override]
	public function write(string $path, string $contents): void {
		$this->files[$path] = $contents;
	}

	#[Override]
	public function exists(string $path): bool {
		return array_key_exists($path, $this->files);
	}

	#[Override]
	public function digest(string $path): string {
		return $this->digests[$path] ?? hash("sha256", $this->read($path));
	}
}
