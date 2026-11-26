<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Unit\Coverage;

use Kristos80\Mule\Coverage\Capture;
use Kristos80\Mule\Failure;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class CaptureTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function payload(array $overrides = []): array {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds(["AaaTest::testOne"]);
		$coverage->setLineCoverage(["/src/A.php" => [1 => [0 => 1]]]);

		return [
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => Capture::FORMAT]],
			"basePath" => "/src",
			"codeCoverage" => $coverage,
			"testResults" => ["AaaTest::testOne" => ["size" => "u", "status" => "success", "time" => 0.1]],
			...$overrides,
		];
	}

	public function testReadsAWellFormedPayload(): void {
		$capture = Capture::of($this->payload());

		self::assertSame("/src", $capture->basePath);
		self::assertSame(["AaaTest::testOne"], $capture->testIds());
	}

	public function testRefusesAPayloadThatIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A coverage payload must be an array, string given.");

		Capture::of("not a payload");
	}

	public function testNamesWhicheverKeyIsMissing(): void {
		$caught = [];

		foreach(["buildInformation", "basePath", "codeCoverage", "testResults"] as $key) {
			$payload = $this->payload();
			unset($payload[$key]);

			try {
				Capture::of($payload);
			} catch(Failure $failure) {
				$caught[$key] = $failure->getMessage();
			}
		}

		self::assertSame([
			"buildInformation" => 'A coverage payload is missing its "buildInformation" key.',
			"basePath" => 'A coverage payload is missing its "basePath" key.',
			"codeCoverage" => 'A coverage payload is missing its "codeCoverage" key.',
			"testResults" => 'A coverage payload is missing its "testResults" key.',
		], $caught);
	}

	public function testRefusesCoverageThatIsNotProcessedData(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('"codeCoverage" is not a ProcessedCodeCoverageData');

		Capture::of($this->payload(["codeCoverage" => ["not" => "coverage"]]));
	}

	/**
	 * M2: mule reads php-code-coverage's serialized shape rather than a public
	 * API, so an unrecognised format is a refusal. The alternative is shards
	 * built from a layout that moved under a transitive version bump.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownSerializationFormat(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage(
			"mule reads php-code-coverage serialization format 4, this payload declares 5."
			. " Pinning phpunit/php-code-coverage is what keeps these in step.",
		);

		Capture::of($this->payload([
			"buildInformation" => ["phpCodeCoverage" => ["serializationFormat" => 5]],
		]));
	}

	public function testRefusesBuildInformationWithNoFormatAtAll(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A coverage payload's build information does not declare a serialization format.");

		Capture::of($this->payload(["buildInformation" => []]));
	}

	/**
	 * Build information that is an array but whose `phpCodeCoverage` entry is
	 * not reaches the same refusal as one with no entry at all: both are a
	 * payload that does not say what format it is in.
	 *
	 * @return void
	 */
	public function testRefusesBuildInformationWhosePhpCodeCoverageEntryIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A coverage payload's build information does not declare a serialization format.");

		Capture::of($this->payload(["buildInformation" => ["phpCodeCoverage" => "nonsense"]]));
	}

	public function testRefusesBuildInformationThatIsNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A coverage payload's build information must be an array, string given.");

		Capture::of($this->payload(["buildInformation" => "nonsense"]));
	}

	public function testRefusesABasePathThatIsNotAString(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage('A coverage payload\'s "basePath" is not a string.');

		Capture::of($this->payload(["basePath" => 42]));
	}

	public function testRefusesTestResultsThatAreNotAnArray(): void {
		$this->expectException(Failure::class);
		$this->expectExceptionMessage("A coverage payload's test results must be an array, int given.");

		Capture::of($this->payload(["testResults" => 42]));
	}

	public function testWithKeepsTheBuildInformationSoAShardStillDeclaresItsFormat(): void {
		$capture = Capture::of($this->payload());
		$other = new ProcessedCodeCoverageData();
		$other->setTestIds(["BbbTest::testTwo"]);

		$derived = $capture->with($other, [], "/elsewhere");

		self::assertSame($capture->buildInformation, $derived->buildInformation);
		self::assertSame("/elsewhere", $derived->basePath);
		self::assertSame([], $derived->testResults);
	}

	/**
	 * The class is what the timings are keyed by (G4), so this has to agree with
	 * `JUnitTestExecutionInfoAdder`: split on the first `:`, not on `::`.
	 *
	 * @return void
	 */
	public function testNamesTheTestClassesItAttributesCoverageTo(): void {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([
			"Demo\\AaaTest::testOne",
			"Demo\\BbbTest::testTwo",
			// a second method of a class already seen, which must not repeat
			"Demo\\AaaTest::testThree",
			// a provider case, whose dataset name may itself contain a colon
			"Demo\\CccTest::testFour#a: dataset",
			// and an id with no colon at all
			"Demo\\DddTest",
		]);

		self::assertSame(
			["Demo\\AaaTest", "Demo\\BbbTest", "Demo\\CccTest", "Demo\\DddTest"],
			Capture::of($this->payload(["codeCoverage" => $coverage]))->testClasses(),
		);
	}

	/**
	 * `explode(":", ":x")[0]` is `""`, so an id beginning with a colon yields a
	 * class name no JUnit lookup can match — G4 would pass and Infection would
	 * then throw `TestNotFound` somewhere further on.
	 *
	 * @return void
	 */
	public function testRefusesAnIdThatNamesNoTestClass(): void {
		$coverage = new ProcessedCodeCoverageData();
		$coverage->setTestIds([":testOne"]);

		$this->expectException(Failure::class);
		$this->expectExceptionMessage(
			'A coverage payload attributes coverage to ":testOne", which names no test class.',
		);

		Capture::of($this->payload(["codeCoverage" => $coverage]))->testClasses();
	}

	public function testPayloadRoundTripsBackIntoACapture(): void {
		$payload = Capture::of($this->payload())->payload();

		self::assertSame(
			["buildInformation", "basePath", "codeCoverage", "testResults"],
			array_keys($payload),
		);
		self::assertSame("/src", Capture::of($payload)->basePath);
	}
}
