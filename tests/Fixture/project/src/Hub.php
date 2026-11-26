<?php declare(strict_types=1);
namespace Fixture;

/** A value object nearly everything constructs. Several test classes reach it. */
final readonly class Hub {
	public function __construct(public int $size) {}

	public function fits(int $limit): bool {
		return $this->size <= $limit;
	}

	/**
	 * Deliberately under-tested: nothing exercises the boundary, so `>` mutated
	 * to `>=` survives. The differential needs a verdict that is not "killed",
	 * or it would only ever be comparing counts.
	 */
	public function clamped(int $limit): int {
		return $this->size > $limit ? $limit : $this->size;
	}
}
