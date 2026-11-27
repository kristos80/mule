<?php
declare(strict_types=1);

namespace Kristos80\Mule\Domain\Exception;

use RuntimeException;

/**
 * Discovery could not be trusted, so nothing downstream ran.
 *
 * **Every refusal carries its own message.** Not for the reader's comfort: a
 * guard that falls through to a neighbour's message is a guard no test can tell
 * from its neighbour, and therefore a mutant that survives for ever.
 */
final class DiscoveryException extends RuntimeException {}
