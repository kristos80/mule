<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Shared\Path;

use Kristos80\Mule\Shared\Path\RelativePathResolver;
use Kristos80\Mule\Tests\Support\StubConfiguration;
use PHPUnit\Framework\TestCase;

final class RelativePathResolverTest extends TestCase {

	public function testStripsTheProjectRoot(): void {
		$resolver = new RelativePathResolver(new StubConfiguration(root: "/home/me/app"));

		self::assertSame("src/Domain/Order.php", $resolver->resolve("/home/me/app/src/Domain/Order.php"));
	}

	public function testStripsTheProjectRootWhenItWasConfiguredWithATrailingSeparator(): void {
		$resolver = new RelativePathResolver(new StubConfiguration(root: "/home/me/app/"));

		self::assertSame("src/Order.php", $resolver->resolve("/home/me/app/src/Order.php"));
	}

	/**
	 * Truncating a path that is not under the root would hide which file a
	 * diagnostic is about, so it survives intact.
	 */
	public function testLeavesAPathOutsideTheProjectUntouched(): void {
		$resolver = new RelativePathResolver(new StubConfiguration(root: "/home/me/app"));

		self::assertSame("/elsewhere/vendor/Thing.php", $resolver->resolve("/elsewhere/vendor/Thing.php"));
	}

	/**
	 * A sibling directory whose name merely starts with the root's is not inside
	 * it, which is why the separator is part of the prefix.
	 */
	public function testDoesNotTreatASiblingDirectoryAsBeingInsideTheProject(): void {
		$resolver = new RelativePathResolver(new StubConfiguration(root: "/home/me/app"));

		self::assertSame("/home/me/application/src/Order.php", $resolver->resolve("/home/me/application/src/Order.php"));
	}
}
