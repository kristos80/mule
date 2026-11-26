<?php declare(strict_types=1);
namespace Fixture;

/** No mutable code at all, which must read as "legitimately zero", not as G1. */
interface Nothing {
	public function nothing(): void;
}
