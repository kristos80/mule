<?php
declare(strict_types=1);

namespace Kristos80\Mule\Tests\Support;

use Kristos80\Mule\External\RandomInterface;

/**
 * A token a test can predict, which is what lets it assert the paths mule hands
 * to a coverage run and to the engine.
 */
final class FixedRandom implements RandomInterface {

	/**
	 * @param string $token
	 */
	public function __construct(
		private string $token = "cafe1234",
	) {}

	/**
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}
}
