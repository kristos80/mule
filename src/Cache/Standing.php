<?php
declare(strict_types=1);

namespace Kristos80\Mule\Cache;

/**
 * What the store can still say for itself, worked out from digests alone.
 *
 * **This is the half of the cache that needs no coverage report.** Which files
 * are stale was otherwise knowable only by generating one, which made the cheap
 * question depend on the expensive answer: a run had to spend the whole coverage
 * pass to discover it had nothing to do. The store records what each test
 * executes, so the same question is a few hundred digests — measured at 0.4 s on
 * a 613-file project, with no driver and no test process.
 *
 * **It refuses rather than guesses.** A file nobody recorded that has appeared,
 * or a toolchain that no longer matches, and the answer is "ask a coverage run" —
 * because the store is describing a tree that is not the one on disk. The only
 * case it answers for itself is the one it can be sure of.
 */
final readonly class Standing {

	/**
	 * @param bool $usable Whether the store describes the tree as it is now.
	 * @param list<string> $outstanding What still needs the engine.
	 * @param string $because Why, in a form a person can read.
	 */
	private function __construct(
		public bool $usable,
		public array $outstanding,
		public string $because,
	) {}

	/**
	 * @param Snapshot $stored
	 * @param array<string, non-empty-string> $digests Every file on disk mule cares about, and its digest.
	 * @param non-empty-string $toolchain
	 * @return self
	 */
	public static function of(Snapshot $stored, array $digests, string $toolchain): self {
		if(!$stored->describes($toolchain)) {
			return new self(
				FALSE,
				[],
				"the verification environment has changed, so nothing the store holds was proven under it",
			);
		}

		$appeared = self::appeared($stored, $digests);

		if($appeared !== []) {
			return new self(
				FALSE,
				[],
				count($appeared) . " file(s) the store has never heard of have appeared, starting with "
				. $appeared[0] . "; what they cover cannot be worked out from digests",
			);
		}

		return new self(TRUE, self::outstanding($stored, $digests, $toolchain), "the store describes this tree");
	}

	/**
	 * Files on disk that no part of the store mentions.
	 *
	 * @param Snapshot $stored
	 * @param array<string, non-empty-string> $digests
	 * @return list<string>
	 */
	private static function appeared(Snapshot $stored, array $digests): array {
		$known = array_flip($stored->known());
		$appeared = [];

		foreach(array_keys($digests) as $path) {
			if(!isset($known[$path])) {
				$appeared[] = $path;
			}
		}

		return $appeared;
	}

	/**
	 * Every file the store cannot vouch for: one it never proved, and one whose
	 * key has moved.
	 *
	 * **The key is recomputed rather than the digests compared.** A file's
	 * verdict rests on its own bytes, its covering tests' bytes and the bytes of
	 * everything those tests execute — and the store holds the shape of that,
	 * not a digest of it. Recomputing is how a change two files away is noticed.
	 *
	 * @param Snapshot $stored
	 * @param array<string, non-empty-string> $digests
	 * @param non-empty-string $toolchain
	 * @return list<string>
	 */
	private static function outstanding(Snapshot $stored, array $digests, string $toolchain): array {
		$outstanding = $stored->unproven;

		foreach($stored->verdicts as $path => $verdict) {
			if(!$verdict->holds(self::keyOf($stored, $path, $digests, $toolchain))) {
				$outstanding[] = $path;
			}
		}

		// **No de-duplication**, and that is an invariant rather than an
		// oversight: `Snapshot::with()` takes anything newly proven out of the
		// unproven list and anything newly unproven out of the verdicts, so the
		// two are disjoint and nothing can be in this list twice. The gate found
		// the `array_unique` here by removing it with no effect.
		sort($outstanding);

		return $outstanding;
	}

	/**
	 * @param Snapshot $stored
	 * @param string $path
	 * @param array<string, non-empty-string> $digests
	 * @param non-empty-string $toolchain
	 * @return non-empty-string
	 */
	private static function keyOf(Snapshot $stored, string $path, array $digests, string $toolchain): string {
		return Key::of(
			$path,
			self::digest($digests, $path),
			self::digestsOf($digests, $stored->dependenciesOf($path)),
			self::digestsOf($digests, $stored->coveredBy($path)),
			$toolchain,
		);
	}

	/**
	 * @param array<string, non-empty-string> $digests
	 * @param list<string> $paths
	 * @return array<string, non-empty-string>
	 */
	private static function digestsOf(array $digests, array $paths): array {
		$of = [];

		foreach($paths as $path) {
			$of[$path] = self::digest($digests, $path);
		}

		return $of;
	}

	/**
	 * **A file mule cannot hash is not a file that stayed the same.** A path the
	 * store knows and the tree no longer has gets a digest that matches nothing,
	 * so the verdict resting on it stops holding rather than being skipped.
	 *
	 * @param array<string, non-empty-string> $digests
	 * @param string $path
	 * @return non-empty-string
	 */
	private static function digest(array $digests, string $path): string {
		return $digests[$path] ?? "gone";
	}
}
