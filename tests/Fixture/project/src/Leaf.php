<?php declare(strict_types=1);
namespace Fixture;

/** Reached only through Trunk: no test of its own. */
final readonly class Leaf {
	public function doubled(int $value): int {
		return $value * 2;
	}
}
