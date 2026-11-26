<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\EnvironmentInterface;
use Override;

/**
 * The machine, as a test describes it — which is the only way "a verdict proven
 * under a different PHP" is a case anything can set up.
 */
final readonly class StubEnvironment implements EnvironmentInterface {

	public function __construct(
		private string $interpreter = "/usr/bin/php",
		private string $ini = "/etc/php.ini",
		private string $php = "8.4.28",
		private string $driver = "xdebug 3.4.0",
		private string $mule = "5.0.0",
		private string $directory = "/p",
	) {}

	#[Override]
	public function interpreter(): string {
		return $this->interpreter;
	}

	#[Override]
	public function ini(): string {
		return $this->ini;
	}

	#[Override]
	public function php(): string {
		return $this->php;
	}

	#[Override]
	public function driver(): string {
		return $this->driver;
	}

	#[Override]
	public function mule(): string {
		return $this->mule;
	}

	#[Override]
	public function directory(): string {
		return $this->directory;
	}
}
