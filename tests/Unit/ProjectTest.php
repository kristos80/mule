<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit;

use Kristos80\Mule\Failure;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase {

	/**
	 * @param array<string, string> $resolved
	 * @return Project
	 */
	private function project(array $resolved = []): Project {
		return new Project(new InMemoryFilesystem([], [], [], $resolved), "/p");
	}

	public function testMakesAPathInsideTheProjectRelative(): void {
		self::assertSame("src/A.php", $this->project()->relative("/p/src/A.php"));
	}

	/**
	 * The case this exists for: Composer answers `findFile()` with a path that
	 * walks back out of `vendor/`, and a key taken over two spellings of one
	 * file is two keys for one verdict.
	 *
	 * @return void
	 */
	public function testResolvesBeforeMakingRelative(): void {
		$project = $this->project(["/p/vendor/composer/../../tests/ATest.php" => "/p/tests/ATest.php"]);

		self::assertSame("tests/ATest.php", $project->relative("/p/vendor/composer/../../tests/ATest.php"));
	}

	/**
	 * A file outside the checkout cannot be stored project-relative, and
	 * guessing would put a verdict under a path that means something else on the
	 * next machine.
	 *
	 * @return void
	 */
	public function testRefusesAPathOutsideTheProject(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("/elsewhere/A.php is outside the project at /p.");

		$this->project()->relative("/elsewhere/A.php");
	}

	/**
	 * A sibling directory sharing the root's name is not inside it: `/project`
	 * and `/project-old` share a prefix and nothing else.
	 *
	 * @return void
	 */
	public function testIsNotFooledByASiblingSharingThePrefix(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("/p-old/src/A.php is outside the project at /p.");

		$this->project()->relative("/p-old/src/A.php");
	}

	/**
	 * The root itself is not a path *inside* the project, and answering `""`
	 * would be a verdict keyed on nothing.
	 *
	 * @return void
	 */
	public function testRefusesTheRootItself(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("/p is outside the project at /p.");

		$this->project()->relative("/p");
	}

	public function testPutsARelativePathBackTogether(): void {
		self::assertSame("/p/src/A.php", $this->project()->absolute("src/A.php"));
	}

	public function testTheTwoDirectionsAgree(): void {
		self::assertSame("src/A.php", $this->project()->relative($this->project()->absolute("src/A.php")));
	}
}
