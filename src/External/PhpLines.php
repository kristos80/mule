<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

use Override;
use SebastianBergmann\CodeCoverage\StaticAnalysis\FileAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingSourceAnalyser;

/**
 * php-code-coverage's own static analysis, asked directly.
 *
 * **The same parser that decided what the report would contain.** Any other
 * answer to "is this line executable" would introduce a second opinion, and G3
 * would then be comparing two analysers rather than a report against a file.
 *
 * **Annotations are not honoured, and that is deliberate.** With
 * `useAnnotationsForIgnoringCode` off, a line carrying `@codeCoverageIgnore` is
 * still counted as executable — so a suppression cannot quietly lift a file over
 * the coverage floor. It also keeps this set a superset of the report's, which is
 * the direction G3 needs: a line the report treats as executable and the parser
 * does not is a stale shard, whereas the reverse is somebody's annotation.
 *
 * One construction and one call, so it is excluded from coverage and from
 * mutation like the other wrappers — named in both `phpunit.xml` and
 * `infection.json5`.
 */
final class PhpLines implements LinesInterface {

	/**
	 * The analyser caches per file, so asking about the same file twice in a run
	 * parses it once.
	 */
	private FileAnalyser $analyser;

	public function __construct() {
		// Positionally, because the constructor is `@no-named-arguments`: the two
		// flags are `useAnnotationsForIgnoringCode` and `ignoreDeprecatedCode`,
		// both off for the reason above.
		$this->analyser = new FileAnalyser(new ParsingSourceAnalyser(), FALSE, FALSE);
	}

	#[Override]
	public function executableIn(string $file): array {
		if($file === "") {
			return [];
		}

		$lines = array_keys($this->analyser->analyse($file)->executableLines());

		sort($lines);

		return $lines;
	}
}
