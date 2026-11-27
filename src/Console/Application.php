<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\External\OutputInterface;
use Kristos80\Mule\External\ProgressInterface;
use Kristos80\Mule\Failure;
use function implode;

/**
 * The command.
 *
 * **Three exit codes, and they mean different things.** 0 is "every gated file is
 * proven"; 1 is "mule ran and something is not proven"; 2 is "mule could not
 * answer the question". Collapsing the last two is how a CI pipeline comes to
 * treat a broken run as a failing gate, and then to treat a fixed gate as a fixed
 * run.
 *
 * **A verdict is read off the store, not off this run.** A run that re-proved
 * three files on a project with one long-standing escape exits 1, because the
 * project is not proven — the alternative is a green pipeline whose green means
 * "nothing I looked at today was broken".
 */
final readonly class Application {

	private const string USAGE = <<<'TEXT'
		mule — mutate only the files whose score can no longer be trusted.

		  mule              prove whatever is owed, and nothing else
		  mule --whole      re-prove everything, ignoring the store's verdicts
		  mule doctor       measure whether a verdict cache will pay for itself
		                    here. One coverage capture; writes no store and runs
		                    no mutants
		  mule --help       this

		Exit codes: 0 everything gated is proven · 1 something is not · 2 mule could
		not answer. `doctor` exits 0 unless it could not answer.

		mule reads mule.json if there is one, and needs none. It writes
		.mule/verified.json, which is meant to be committed, and .mule/cache, which
		ignores itself.
		TEXT;

	/**
	 * @param Composition $composition
	 * @param Summary $summary
	 * @param Prognosis $prognosis
	 * @param ProgressInterface $progress
	 * @param OutputInterface $output
	 */
	public function __construct(
		private Composition $composition,
		private Summary $summary,
		private Prognosis $prognosis,
		private ProgressInterface $progress,
		private OutputInterface $output,
	) {}

	/**
	 * @param list<string> $arguments Everything after the program name.
	 * @return int
	 */
	public function __invoke(array $arguments): int {
		if(in_array("--help", $arguments, TRUE) || in_array("-h", $arguments, TRUE)) {
			$this->output->line(self::USAGE);

			return 0;
		}

		// Not reindexed: the keys reach neither the check nor the message, and the
		// gate proved an `array_values` here to be an operation with no result
		// behind it.
		$unknown = array_diff($arguments, ["--whole", "doctor"]);

		if($unknown !== []) {
			return $this->failed("mule does not know " . implode(" ", $unknown) . ". Try --help.");
		}

		if(in_array("doctor", $arguments, TRUE)) {
			return $this->examined();
		}

		return $this->proved(in_array("--whole", $arguments, TRUE));
	}

	/**
	 * @param bool $whole
	 * @return int
	 */
	private function proved(bool $whole): int {
		try {
			$assembly = $this->composition->assembled();
			$finding = ($assembly->run)($whole);
		} catch(Failure $failure) {
			return $this->failed($failure->getMessage());
		} finally {
			$this->progress->done();
		}

		foreach($this->summary->lines($finding, $assembly->universe->excluded()) as $line) {
			$this->output->line($line);
		}

		return $finding->holds() ? 0 : 1;
	}

	/**
	 * **`doctor` has no verdict to give, so it has no failing exit code.** It
	 * answers a question about the shape of a test suite, and a suite whose shape
	 * is unhelpful is not a broken build — it is a reason not to install this.
	 *
	 * @return int
	 */
	private function examined(): int {
		try {
			$diagnosis = ($this->composition->assembled()->examination)();
		} catch(Failure $failure) {
			return $this->failed($failure->getMessage());
		} finally {
			// **Whatever happened, the last stage is closed before anything is
			// printed.** A spinner left spinning puts the next line on top of it.
			$this->progress->done();
		}

		foreach($this->prognosis->lines($diagnosis) as $line) {
			$this->output->line($line);
		}

		return 0;
	}

	/**
	 * **The message, and nothing underneath it.** Every `Failure` mule throws is
	 * written for the person reading it, and a stack trace above the sentence that
	 * explains the problem is how somebody comes to read the trace instead.
	 *
	 * @param string $message
	 * @return int
	 */
	private function failed(string $message): int {
		$this->output->line($message);

		return 2;
	}
}
