<?php
declare(strict_types=1);

/**
 * The fixture project's autoloader.
 *
 * It borrows mule's own vendor for PHPUnit and maps its two namespaces by hand,
 * because a fixture with a `composer.json` of its own would be a second
 * dependency tree to keep pinned.
 */
require __DIR__ . "/../../../vendor/autoload.php";

spl_autoload_register(static function (string $class): void {
	foreach(["Fixture\\Tests\\" => __DIR__ . "/tests/", "Fixture\\" => __DIR__ . "/src/"] as $prefix => $directory) {
		if(str_starts_with($class, $prefix)) {
			$path = $directory . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";

			if(file_exists($path)) {
				require $path;

				return;
			}
		}
	}
});
