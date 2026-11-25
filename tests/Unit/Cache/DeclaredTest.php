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

public function testIncludesTheConfigurationMuleIsGiven(): void {
		$this->filesystem->given("/project/mule.json", '{"mutators": {"default": true}}');

		self::assertSame([
			"composer.json" => hash("sha256", "{}"),
			"mule.json" => hash("sha256", '{"mutators": {"default": true}}'),
		], $this->declared()->digests());
	}

	/**
	 * Sorted, because the answer is compared with `===` against a snapshot
	 * section that is sorted.
	 *
	 * @return void
	 */
	public function testAnswersInPathOrder(): void {
		$this->filesystem->given("/project/mule.json", "declared");

		self::assertSame(["composer.json", "mule.json"], array_keys($this->declared()->digests()));
	}

	/**
	 * A project need not have a mule.json at all, so an absent file is absent
	 * rather than a digest of nothing — which is what makes one appearing a
	 * change {@see \Kristos80\Mule\Cache\Freshness} can see.
	 *
	 * @return void
	 */
	public function testLeavesOutWhatIsNotThere(): void {
		self::assertArrayNotHasKey("mule.json", $this->declared()->digests());
	}

	/**
	 * The engine's own configuration file is not read by anything any more:
	 * mule renders what the engine needs per run from mule.json. Hashing a
	 * file that changes nothing would unprove verdicts for no reason.
	 *
	 * @return void
	 */
	public function testNeverDeclaresAnEngineConfigurationTheProjectStillHas(): void {
		$this->filesystem->given("/project/infection.json5", '{"mutators": {"@default": true}}');

		self::assertArrayNotHasKey("infection.json5", $this->declared()->digests());
	}

		/**
	 * @return Declared
	 */
	private function declared(): Declared {
		return new Declared(Project::containing("/project", $this->filesystem), $this->filesystem);
	}
}
