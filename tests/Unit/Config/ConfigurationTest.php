<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Config;

use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Project;
use Kristos80\Mule\Tests\Support\InMemoryFilesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase {

	private InMemoryFilesystem $filesystem;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->filesystem = (new InMemoryFilesystem())->given("/project/composer.json", "{}");
	}

	public function testInfersAPhpunitCoverageRunForAProjectThatDeclaresNothing(): void {
		self::assertSame([
			"./vendor/bin/phpunit",
			"--coverage-xml={coverage-xml}",
			"--log-junit={junit}",
		], $this->configuration()->coverage);
	}

	public function testInfersAPestCoverageRunForAProjectThatInstalledPest(): void {
		$this->filesystem->given("/project/vendor/bin/pest", "#!/usr/bin/env php");

		self::assertSame("./vendor/bin/pest", $this->configuration()->coverage[0]);
	}

	public function testInfersInfectionOnThePathForAProjectThatCannotDependOnIt(): void {
		self::assertSame("infection", $this->configuration()->binary);
	}

	public function testInfersTheInstalledInfectionWhereThereIsOne(): void {
		$this->filesystem->given("/project/vendor/bin/infection", "#!/usr/bin/env php");

		self::assertSame("./vendor/bin/infection", $this->configuration()->binary);
	}

	public function testInfersNoOptionsAtAll(): void {
		self::assertSame([], $this->configuration()->options);
	}

	public function testReadsEverythingDeclaredInMuleJson(): void {
		$this->givenMuleJson('{
			"coverage": ["herd", "coverage", "./vendor/bin/phpunit", "--coverage-xml={coverage-xml}", "--log-junit={junit}"],
			"binary": "/usr/local/bin/infection",
			"options": ["--threads=max", "--no-progress"]
		}');

		$configuration = $this->configuration();

		self::assertSame([
			"herd",
			"coverage",
			"./vendor/bin/phpunit",
			"--coverage-xml={coverage-xml}",
			"--log-junit={junit}",
		], $configuration->coverage);
		self::assertSame("/usr/local/bin/infection", $configuration->binary);
		self::assertSame(["--threads=max", "--no-progress"], $configuration->options);
	}

	public function testPrefersADeclaredBinaryOverTheInstalledOne(): void {
		$this->filesystem->given("/project/vendor/bin/infection", "#!/usr/bin/env php");
		$this->givenMuleJson('{"binary": "infection"}');

		self::assertSame("infection", $this->configuration()->binary);
	}

	public function testRejectsACoverageCommandThatNamesNoCoverageDirectory(): void {
		$this->givenMuleJson('{"coverage": ["./vendor/bin/phpunit", "--log-junit={junit}"]}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage command in mule.json must pass {coverage-xml} to the test runner");

		$this->configuration();
	}

	public function testRejectsACoverageCommandThatNamesNoJunitLog(): void {
		$this->givenMuleJson('{"coverage": ["./vendor/bin/phpunit", "--coverage-xml={coverage-xml}"]}');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The coverage command in mule.json must pass {junit} to the test runner");

		$this->configuration();
	}

	public function testRejectsAMuleJsonItCannotParse(): void {
		$this->givenMuleJson("{not json");

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Cannot read mule.json: mule needs it to be a JSON object.");

		$this->configuration();
	}

	/**
	 * @param string $contents
	 * @return void
	 */
	private function givenMuleJson(string $contents): void {
		$this->filesystem->given("/project/mule.json", $contents);
	}

	/**
	 * @return Configuration
	 */
	private function configuration(): Configuration {
		return Configuration::load(Project::containing("/project", $this->filesystem), $this->filesystem);
	}
}
