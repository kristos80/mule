<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Cache;

use Kristos80\Mule\Cache\Declared;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * What the tree says about the gate, independently of any store.
 */
final class DeclaredTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())->given("/project/composer.json", "{}");
	}

	public function testDigestsWhatTheProjectDeclares(): void {
		self::assertSame(["composer.json" => hash("sha256", "{}")], $this->declared()->digests());
	}

	public function testIncludesTheEnginesConfiguration(): void {
		$this->filesystem->given("/project/infection.json5", '{"mutators": {"@default": true}}');

		self::assertSame([
			"composer.json" => hash("sha256", "{}"),
			"infection.json5" => hash("sha256", '{"mutators": {"@default": true}}'),
		], $this->declared()->digests());
	}

	/**
	 * Sorted, because the answer is compared with `===` against a snapshot
	 * section that is sorted — and the declared order is not sorted order:
	 * `infection.json5` is looked for before `infection.json`.
	 *
	 * @return void
	 */
	public function testAnswersInPathOrderRatherThanTheOrderItLooks(): void {
		$this->filesystem->given("/project/infection.json5", "five");
		$this->filesystem->given("/project/infection.json", "plain");

		self::assertSame(
			["composer.json", "infection.json", "infection.json5"],
			array_keys($this->declared()->digests()),
		);
	}

	/**
	 * A project has one engine configuration or another and not all of them, so
	 * an absent file is absent rather than a digest of nothing — which is what
	 * makes one appearing a change {@see \Kristos80\Mule\Cache\Freshness} can
	 * see.
	 *
	 * @return void
	 */
	public function testLeavesOutWhatIsNotThere(): void {
		self::assertArrayNotHasKey("infection.json", $this->declared()->digests());
	}

	/**
	 * mule.json is the one file meant to differ between machines — CI deletes
	 * it — so recording it would make a committed store unusable wherever it
	 * differs.
	 *
	 * @return void
	 */
	public function testNeverDeclaresMulesOwnConfiguration(): void {
		$this->filesystem->given("/project/mule.json", '{"options": []}');

		self::assertArrayNotHasKey("mule.json", $this->declared()->digests());
	}

	/**
	 * @return Declared
	 */
	private function declared(): Declared {
		return new Declared(Project::containing("/project", $this->filesystem), $this->filesystem);
	}
}
