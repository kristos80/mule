<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit;

use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProjectTest extends TestCase {

	public function testFindsTheProjectADirectoryIs(): void {
		$filesystem = (new InMemoryFilesystem())->given("/project/composer.json", "{}");

		self::assertSame("/project", Project::containing("/project", $filesystem)->root);
	}

	public function testFindsTheProjectADirectoryIsUnder(): void {
		$filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", "{}")
			->givenDirectory("/project/src/Cache");

		self::assertSame("/project", Project::containing("/project/src/Cache", $filesystem)->root);
	}

	public function testStopsAtTheNearestComposerJson(): void {
		$filesystem = (new InMemoryFilesystem())
			->given("/project/composer.json", "{}")
			->given("/project/packages/inner/composer.json", "{}");

		self::assertSame("/project/packages/inner", Project::containing("/project/packages/inner", $filesystem)->root);
	}

	public function testRejectsATreeWithNoComposerJsonAnywhereAboveIt(): void {
		$filesystem = (new InMemoryFilesystem())->givenDirectory("/elsewhere");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("No composer.json at or above /elsewhere: mule needs a project root.");

		Project::containing("/elsewhere", $filesystem);
	}

	/**
	 * The walk itself, and not only where it ends: every directory from the one
	 * mule was pointed at up to the root, each asked about once. A bound that
	 * counted wrong would be invisible in the answer, because a walk that
	 * overshoots the root keeps asking about the root.
	 *
	 * @return void
	 */
	public function testAsksAboutEveryDirectoryFromTheOneItWasGivenUpToTheRoot(): void {
		$filesystem = (new InMemoryFilesystem())->givenDirectory("/project/src/Cache");

		try {
			Project::containing("/project/src/Cache", $filesystem);

			self::fail("A tree with no composer.json anywhere above it is not a project.");
		} catch(RuntimeException) {
			// Expected, and asserted on elsewhere. What this test is about is
			// the walk the failure took, which is read off the filesystem below.
		}

		self::assertSame([
			"/project/src/Cache",
			"/project/src/Cache/composer.json",
			"/project/src/composer.json",
			"/project/composer.json",
			"/composer.json",
		], $filesystem->probes());
	}

	public function testRelativisesAPathInsideTheProject(): void {
		self::assertSame("src/Cache/Key.php", self::project()->relative("/project/src/Cache/Key.php"));
	}

	/**
	 * The case this exists for: a path as Composer's autoloader spells it. Asked
	 * where a psr-4 test class lives, it answers out of its own generated
	 * configuration — `{root}/vendor/composer/../../tests/…` for a prefix mapped
	 * to `tests` — and that answer is half of every key in the store.
	 *
	 * @return void
	 */
	public function testRelativisesAPathTheWayItsWriterSpelledIt(): void {
		self::assertSame(
			"tests/Unit/Cache/KeyTest.php",
			self::project()->relative("/project/vendor/composer/../../tests/Unit/Cache/KeyTest.php"),
		);
	}

	public function testRelativisesAPathWithADotSegmentInIt(): void {
		self::assertSame("src/Cache/Key.php", self::project()->relative("/project/./src/Cache/Key.php"));
	}

	public function testDropsAClimbAboveTheTopOfTheTree(): void {
		self::assertSame("src/Cache/Key.php", self::project()->relative("/../project/src/Cache/Key.php"));
	}

	/**
	 * A path that starts with the root as a *string* while naming a file outside
	 * it, which is what the containment guard is for and what it could not see
	 * while the segments were left as given.
	 *
	 * @return void
	 */
	public function testRejectsAPathThatClimbsOutOfTheProject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("/elsewhere/Key.php is outside the project root /project, so mule cannot record it.");

		self::project()->relative("/project/vendor/composer/../../../elsewhere/Key.php");
	}

	public function testRejectsAPathOutsideTheProject(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("/elsewhere/Key.php is outside the project root /project, so mule cannot record it.");

		self::project()->relative("/elsewhere/Key.php");
	}

	public function testRejectsAPathThatOnlyLooksLikeTheRoot(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("is outside the project root");

		self::project()->relative("/project-other/src/Key.php");
	}

	public function testHoldsAPathInsideItself(): void {
		self::assertTrue(self::project()->contains("/project/src/Cache/Key.php"));
	}

	public function testDoesNotHoldAPathOutsideItself(): void {
		self::assertFalse(self::project()->contains("/elsewhere/Key.php"));
		self::assertFalse(self::project()->contains("/project-other/src/Key.php"));
	}

	public function testDoesNotHoldAPathThatClimbsBackOutOfIt(): void {
		self::assertFalse(self::project()->contains("/project/vendor/composer/../../../elsewhere/Key.php"));
	}

	public function testAbsolutisesARecordedPath(): void {
		self::assertSame("/project/src/Cache/Key.php", self::project()->absolute("src/Cache/Key.php"));
	}

	/**
	 * @return Project
	 */
	private static function project(): Project {
		return Project::containing("/project", (new InMemoryFilesystem())->given("/project/composer.json", "{}"));
	}
}
