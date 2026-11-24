<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Coverage\Unreached;

/**
 * A source file the tests do not reach all of, as the store remembers it.
 *
 * **Recorded rather than merely skipped.** A file that cannot be banked is
 * otherwise indistinguishable from one nobody has proven yet: it would sit in
 * the stale count run after run with no reason attached, and a run that
 * answers from the store rather than from a fresh report would have nothing to
 * say about it at all. With the gap written down, mule can tell you where the
 * tests stop without re-measuring.
 */
final readonly class Unproven {

	/**
	 * @param string $digest The source file's digest when the gap was measured.
	 * @param list<Unreached> $unreached Where the tests stopped.
	 */
	public function __construct(
		public string $digest,
		public array $unreached,
	) {}

	/**
	 * As the store writes it.
	 *
	 * @return array{digest: string, unreached: list<array{name: string, from: int, to: int, lines: int}>}
	 */
	public function toArray(): array {
		$unreached = [];

		foreach($this->unreached as $one) {
			$unreached[] = $one->toArray();
		}

		return ["digest" => $this->digest, "unreached" => $unreached];
	}
}
