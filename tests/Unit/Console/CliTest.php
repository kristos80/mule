<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Console;

use Kristos80\Mule\Console\Cli;
use Kristos80\Mule\Console\Verb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Cli::class)]
final class CliTest extends TestCase {

	public function testReadsEveryVerbMuleHas(): void {
		self::assertSame(Verb::Narrowed, Cli::verbOf(["new"]));
		self::assertSame(Verb::Full, Cli::verbOf(["full"]));
		self::assertSame(Verb::Status, Cli::verbOf(["status"]));
	}

	public function testRejectsACommandLineWithNoVerb(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("No verb given. usage: mule new|full|status");

		Cli::verbOf([]);
	}

	public function testRejectsAVerbItDoesNotHave(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Unknown verb diff. usage: mule new|full|status");

		Cli::verbOf(["diff"]);
	}

	public function testRejectsAnArgumentAfterTheVerb(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Unexpected argument --filter=src/A.php. usage: mule new|full|status");

		Cli::verbOf(["new", "--filter=src/A.php"]);
	}
}
