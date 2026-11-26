<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Integration;

use Composer\Autoload\ClassLoader;
use Kristos80\Mule\External\ComposerClasses;
use Kristos80\Mule\External\PhpCoverageReport;
use Kristos80\Mule\External\PhpFilesystem;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * The two wrappers against a real filesystem.
 *
 * They are the only code excluded from the unit suite's coverage and from the
 * mutation gate, on the grounds that there is no decision in them to assert.
 * That is an argument for not unit testing them, not for never running them —
 * so each primitive is exercised here, in both directions, against a real
 * temporary directory.
 */
final class ExternalTest extends TestCase {

	use TemporaryTree;

	public function testWritesAndReadsBackTheSameBytes(): void {
		$filesystem = new PhpFilesystem();

		$filesystem->write($this->at("a.txt"), "the bytes\n");

		self::assertSame("the bytes\n", $filesystem->read($this->at("a.txt")));
	}

	public function testKnowsWhatExists(): void {
		$filesystem = new PhpFilesystem();
		$filesystem->write($this->at("a.txt"), "x");

		self::assertTrue($filesystem->exists($this->at("a.txt")));
		self::assertFalse($filesystem->exists($this->at("nothing.txt")));
	}

	public function testTellsADirectoryFromAFile(): void {
		$filesystem = new PhpFilesystem();
		$filesystem->write($this->at("a.txt"), "x");

		self::assertTrue($filesystem->isDirectory($this->tree));
		self::assertFalse($filesystem->isDirectory($this->at("a.txt")));
		self::assertFalse($filesystem->isDirectory($this->at("nothing")));
	}

	public function testCreatesWhateverIsMissingAboveADirectory(): void {
		$filesystem = new PhpFilesystem();

		$filesystem->createDirectory($this->at("one/two/three"));

		self::assertTrue($filesystem->isDirectory($this->at("one/two/three")));
	}

	/**
	 * `mkdir` answers FALSE for a directory that is already there, and the
	 * caller is the one that decides whether that matters — so the wrapper
	 * reports it rather than swallowing it.
	 *
	 * @return void
	 */
	public function testRefusesToCreateADirectoryThatIsAlreadyThere(): void {
		$filesystem = new PhpFilesystem();
		$filesystem->createDirectory($this->at("one"));

		$this->expectException(Failure::class);
		$this->expectExceptionMessage("Cannot create the directory");

		$filesystem->createDirectory($this->at("one"));
	}

	public function testDigestsAFilesBytes(): void {
		$filesystem = new PhpFilesystem();
		$filesystem->write($this->at("a.txt"), "the bytes");

		self::assertSame(hash("sha256", "the bytes"), $filesystem->digest($this->at("a.txt")));
	}

	public function testADigestFollowsTheBytesRatherThanTheName(): void {
		$filesystem = new PhpFilesystem();
		$filesystem->write($this->at("a.txt"), "before");
		$before = $filesystem->digest($this->at("a.txt"));
		$filesystem->write($this->at("a.txt"), "after");

		self::assertNotSame($before, $filesystem->digest($this->at("a.txt")));
	}

	public function testReadingWhatIsNotThereFailsWithThePath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("Cannot read {$this->at("nothing.txt")}.");

		new PhpFilesystem()->read($this->at("nothing.txt"));
	}

	public function testHashingWhatIsNotThereFailsWithThePath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("Cannot hash {$this->at("nothing.txt")}.");

		new PhpFilesystem()->digest($this->at("nothing.txt"));
	}

	public function testWritingWhereThereIsNoDirectoryFailsWithThePath(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("Cannot write {$this->at("missing/a.txt")}.");

		new PhpFilesystem()->write($this->at("missing/a.txt"), "x");
	}

	/**
	 * A capture shaped the way `--coverage-php` writes one: file paths **relative
	 * to the base path**, not absolute (M17). A fixture with absolute paths
	 * renders nothing at all, because `PathReducer` strips the base path a second
	 * time — so this is the shape, and getting it wrong here is how a green
	 * integration suite would still ship a broken renderer.
	 *
	 * @param non-empty-list<non-empty-string> $relative
	 * @return ProcessedCodeCoverageData
	 */
	private function coverageOf(array $relative): ProcessedCodeCoverageData {
		$lines = [];

		foreach($relative as $path) {
			$absolute = $this->at("src/{$path}");

			if(!is_dir(dirname($absolute))) {
				mkdir(dirname($absolute), 0o700, TRUE);
			}

			file_put_contents($absolute, "<?php\nfunction f(): int { return 1; } // a distinctive comment\n");
			$lines[$path] = [2 => [0 => 1]];
		}

		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds(["Demo\\ThingTest::testThing"]);
		$coverage->setLineCoverage($lines);

		return $coverage;
	}

	/**
	 * @return array<non-empty-string, array{size: string, status: string, time: float}>
	 */
	private function results(): array {
		return ["Demo\\ThingTest::testThing" => ["size" => "unknown", "status" => "success", "time" => 0.5]];
	}

	/**
	 * @param non-empty-list<non-empty-string> $relative
	 * @return void
	 */
	private function render(array $relative): void {
		new PhpCoverageReport()->renderXml(
			$this->coverageOf($relative),
			$this->results(),
			$this->at("src"),
			$this->at("out/coverage-xml"),
		);
	}

	public function testRendersAnIndexAndADocumentPerSourceFile(): void {
		$this->render(["Thing.php", "Deep/Other.php"]);

		self::assertFileExists($this->at("out/coverage-xml/index.xml"));
		self::assertFileExists($this->at("out/coverage-xml/Thing.php.xml"));
		self::assertFileExists($this->at("out/coverage-xml/Deep/Other.php.xml"));
	}

	/**
	 * M1: Infection's index parser asserts this attribute is present and not
	 * zero before it will read anything else, and M17 is what makes it non-zero
	 * — an absolute-path fixture reports `executed="0"` here.
	 *
	 * @return void
	 */
	public function testTheIndexCarriesTheExecutedCountInfectionAssertsOn(): void {
		$this->render(["Thing.php"]);

		self::assertMatchesRegularExpression(
			'/<lines[^>]+executed="[1-9]/',
			$this->contents($this->at("out/coverage-xml/index.xml")),
		);
	}

	/**
	 * M9: the root the report is relative to is the base path given, not one
	 * derived from the files — and not that base path twice over.
	 *
	 * @return void
	 */
	public function testTheProjectSourceIsTheBasePathExactlyOnce(): void {
		$this->render(["Thing.php", "Deep/Other.php"]);

		self::assertStringContainsString(
			'<project source="' . $this->at("src") . '"',
			$this->contents($this->at("out/coverage-xml/index.xml")),
		);
	}

	public function testTheDocumentNamesTheCoveringTest(): void {
		$this->render(["Thing.php"]);

		self::assertStringContainsString(
			'<covered by="Demo\ThingTest::testThing"',
			$this->contents($this->at("out/coverage-xml/Thing.php.xml")),
		);
	}

	/**
	 * `FALSE` for the source, which is what keeps the report small: the
	 * documents carry line data only, and Infection reads nothing else.
	 *
	 * @return void
	 */
	public function testTheDocumentsCarryNoSource(): void {
		$this->render(["Thing.php"]);

		self::assertStringNotContainsString(
			"a distinctive comment",
			$this->contents($this->at("out/coverage-xml/Thing.php.xml")),
		);
	}

	/**
	 * Composer's loader, asked about a class it knows and one it does not.
	 *
	 * Half of every key is the test files covering a source file, and a coverage
	 * report names those as classes — so this is the only thing that turns one
	 * into the other, and it has to be the project's own loader doing it.
	 *
	 * @return void
	 */
	public function testResolvesAClassToItsFileThroughTheProjectsOwnLoader(): void {
		$loader = new ClassLoader();
		$loader->addPsr4("Fixture\\", $this->at("src"));

		$classes = new ComposerClasses($loader);
		mkdir($this->at("src"), 0o700, TRUE);
		file_put_contents($this->at("src/Thing.php"), "<?php\nnamespace Fixture;\nclass Thing {}\n");

		self::assertSame($this->at("src/Thing.php"), $classes->fileOf("Fixture\\Thing"));
	}

	/**
	 * A covering test the loader cannot place is a test whose edits would
	 * silently stop invalidating anything — so the port answers, and the caller
	 * is the one that complains.
	 *
	 * @return void
	 */
	public function testAnswersFalseForAClassTheLoaderCannotPlace(): void {
		self::assertFalse(new ComposerClasses(new ClassLoader())->fileOf("Nope\\NotHere"));
	}

	/**
	 * It resolves without loading, which is what makes it safe to ask about
	 * every covering test in a report.
	 *
	 * @return void
	 */
	public function testResolvingDoesNotLoadTheClass(): void {
		$loader = new ClassLoader();
		$loader->addPsr4("Fixture\\", $this->at("src"));
		mkdir($this->at("src"), 0o700, TRUE);
		file_put_contents($this->at("src/Unloadable.php"), "<?php\nnamespace Fixture;\nthrow new \\RuntimeException(\"loaded\");\n");

		self::assertIsString(new ComposerClasses($loader)->fileOf("Fixture\\Unloadable"));
		self::assertFalse(class_exists("Fixture\\Unloadable", FALSE));
	}
}
