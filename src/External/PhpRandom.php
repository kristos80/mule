<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Eight bytes from the system CSPRNG, as hexadecimal.
 */
final readonly class PhpRandom implements RandomInterface {

	/**
	 * @return string
	 */
	public function token(): string {
		return bin2hex(random_bytes(8));
	}
}
