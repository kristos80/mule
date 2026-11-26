<?php declare(strict_types=1);
namespace Fixture;

final readonly class Trunk {
	public function __construct(private Leaf $leaf) {}

	public function quadrupled(Hub $hub): int {
		return $this->leaf->doubled($this->leaf->doubled($hub->size));
	}
}
