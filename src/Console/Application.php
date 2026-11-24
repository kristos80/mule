<?php
declare(strict_types=1);

namespace Kristos80\Mule\Console;

use Kristos80\Mule\Cache\Freshness;
use Kristos80\Mule\Cache\Keying;
use Kristos80\Mule\Cache\Store;
use Kristos80\Mule\Cache\Universe;
use Kristos80\Mule\Config\Configuration;
use Kristos80\Mule\Engine\Infection;
use Kristos80\Mule\External\ClassesInterface;
use Kristos80\Mule\External\FilesystemInterface;
use Kristos80\Mule\External\OutputInterface;
use Kristos80\Mule\External\ProcessesInterface;
use Kristos80\Mule\External\RandomInterface;
use Kristos80\Mule\Failure;
use Kristos80\Mule\Gate;
use Kristos80\Mule\Project;
use Kristos80\Mule\Runner\Scratch;
use Throwable;

/**
 * Mule as a program: the five external interfaces in, an exit status out.
 *
 * Everything between is decided by classes that take those interfaces, so the
 * whole of this — argument parsing, project discovery, configuration, keying,
 * the command line handed to the engine, what is banked and what is printed —
 * is exercised over an array in memory.
 */
final readonly class Application {

	/**
	 * @param FilesystemInterface $filesystem
	 * @param ProcessesInterface $processes
	 * @param ClassesInterface $classes
	 * @param RandomInterface $random
	 * @param OutputInterface $output
	 * @param OutputInterface $errors
	 */
	public function __construct(
		private FilesystemInterface $filesystem,
		private ProcessesInterface $processes,
		private ClassesInterface $classes,
		private RandomInterface $random,
		private OutputInterface $output,
		private OutputInterface $errors,
	) {}

	/**
	 * @param list<string> $arguments The command line without the program name.
	 * @param string $directory Where mule was invoked.
	 * @return int
	 */
	public function run(array $arguments, string $directory): int {
		try {
			return $this->dispatch(Cli::verbOf($arguments), $directory);
		} catch(Failure $failure) {
			$this->errors->line(Summary::failed($failure->getMessage()));

			return 1;
		} catch(Throwable $fault) {
			$this->errors->line(Summary::crashed($fault::class, $fault->getMessage()));

			return 1;
		}
	}

	/**
	 * @param Verb $verb
	 * @param string $directory
	 * @return int
	 */
	private function dispatch(Verb $verb, string $directory): int {
		$gate = $this->gateFor($directory);

		return match($verb) {
			Verb::Narrowed => $gate->narrowed(),
			Verb::Full => $gate->full(),
			Verb::Status => $gate->status(),
		};
	}

	/**
	 * @param string $directory
	 * @return Gate
	 */
	private function gateFor(string $directory): Gate {
		$project = Project::containing($directory, $this->filesystem);
		$configuration = Configuration::load($project, $this->filesystem);
		$universe = new Universe($project, $this->filesystem, $this->classes);

		return new Gate(
			$project,
			$configuration,
			new Keying($project, $this->filesystem, $this->classes, $universe),
			new Freshness($project, $this->filesystem, $universe),
			new Store($project, $this->filesystem, $this->random),
			new Infection($configuration->binary, $configuration->options),
			new Scratch($this->filesystem, $this->random),
			$this->processes,
			$this->output,
		);
	}
}
