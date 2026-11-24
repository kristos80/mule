<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

use Kristos80\Mule\Coverage\Unreached;

/**
 * What the store can still say for itself, worked out from digests alone.
 *
 * **Usable is not the same as settled.** Usable means the store describes the
 * tree it is looking at — nothing it recorded has vanished, nothing it never
 * recorded has appeared, and the files it watches rather than proves are
 * untouched. Settled means that *and* there is nothing left to do. A run can
 * answer from a usable store and still have work in it.
 */
final readonly class Standing {

	/**
	 * @param bool $usable Whether the store describes the tree as it is now.
	 * @param list<string> $stale What it proved and can no longer vouch for.
	 * @param array<string, list<Unreached>> $gaps Where the tests were last seen to stop.
	 * @param int $total Every file it knows about, proven or not.
	 */
	private function __construct(
		public bool $usable,
		public array $stale,
		public array $gaps,
		public int $total,
	) {}

	/**
	 * A store that cannot answer: empty, or describing a different tree.
	 *
	 * @return self
	 */
	public static function unusable(): self {
		return new self(FALSE, [], [], 0);
	}

	/**
	 * @param list<string> $stale
	 * @param array<string, list<Unreached>> $gaps
	 * @param int $total
	 * @return self
	 */
	public static function of(array $stale, array $gaps, int $total): self {
		return new self(TRUE, $stale, $gaps, $total);
	}

	/**
	 * Whether what the store says is still true of the tree, which is what
	 * lets a report be answered without generating one.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		return $this->usable && $this->stale === [];
	}

	/**
	 * Whether there is also nothing left to mutate.
	 *
	 * @return bool
	 */
	public function isSettled(): bool {
		return $this->isCurrent() && $this->gaps === [];
	}
}
