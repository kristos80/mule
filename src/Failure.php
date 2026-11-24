<?php
declare(strict_types=1);

namespace Kristos80\Mule;

use RuntimeException;

/**
 * Something about the project, its configuration or its filesystem that stops
 * mule from doing its job.
 *
 * **Everything mule throws on purpose is one of these, and nothing else is.**
 * That is the whole point of the class: `Application` can then tell a failure
 * it predicted — a `mule.json` it cannot parse, a covering test the autoloader
 * cannot place, a coverage report naming no project — from a fault in mule
 * itself, and say which one it is. Before this, both arrived as
 * `mule: <message>` and exit 1, so a bug here read exactly like a
 * misconfiguration there.
 *
 * It extends `RuntimeException` rather than replacing it, so a caller that was
 * catching one still catches this.
 */
final class Failure extends RuntimeException {}
