<?php
declare(strict_types=1);

namespace Kristos80\Mule;

use RuntimeException;

/**
 * Everything mule throws on purpose.
 *
 * Anything else reaching the top of the application is reported as a fault in
 * mule rather than a mistake in the project being gated, so nothing in `src/`
 * throws a bare `RuntimeException`.
 */
final class Failure extends RuntimeException {}
