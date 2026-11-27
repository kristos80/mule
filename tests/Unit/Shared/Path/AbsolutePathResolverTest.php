<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Shared\Path;

use Kristos80\Mule\Shared\Path\AbsolutePathResolver;
use Kristos80\Mule\Tests\Support\StubConfiguration;
use PHPUnit\Framework\TestCase;

final class AbsolutePathResolverTest extends TestCase {

	public function testJoinsAProjectRelativePathToTheRoot(): void {
		$resolver = new AbsolutePathResolver(new StubConfiguration(root: "/home/me/app"));

		self::assertSame("/home/me/app/src/Order.php", $resolver->resolve("src/Order.php"));
	}

	public function testDoesNotDoubleTheSeparatorWhenTheRootCarriesOne(): void {
		$resolver = new AbsolutePathResolver(new StubConfiguration(root: "/home/me/app/"));

		self::assertSame("/home/me/app/src/Order.php", $resolver->resolve("src/Order.php"));
	}

	public function testResolvesAnEmptyPathToTheProjectRootItself(): void {
		$resolver = new AbsolutePathResolver(new StubConfiguration(root: "/home/me/app"));

		self::assertSame("/home/me/app", $resolver->resolve(""));
	}
}
