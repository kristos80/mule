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
	 * @param list<string> $unmeasured Test files whose coverage has to be captured again.
	 * @param string $because Why, in a form a person can read.
	 */
	private function __construct(
		public bool $usable,
		public array $outstanding,
		public array $unmeasured,
		public string $because,
	) {}

	/**
	 * @param Snapshot $stored
	 * @param array<string, non-empty-string> $digests Every file on disk mule cares about, and its digest.
	 * @param list<string> $gated The source files mule is answerable for.
	 * @param non-empty-string $toolchain
	 * @return self
	 */
	public static function of(Snapshot $stored, array $digests, array $gated, string $toolchain): self {
		if(!$stored->describes($toolchain)) {
			return new self(
				FALSE,
				[],
				[],
				"the verification environment has changed, so nothing the store holds was proven under it",
			);
		}

		$appeared = self::appeared($stored, $digests);

		if($appeared !== []) {
			return new self(
				FALSE,
				[],
				[],
				count($appeared) . " file(s) the store has never heard of have appeared, starting with "
				. $appeared[0] . "; what they cover cannot be worked out from digests",
			);
		}

		// **Two facts, not one.** What is owed is about verdicts; what is unmeasured
		// is about coverage, and a test that covers nothing has its bytes in no
		// verdict's key at all — so asking only the first question would answer
		// "nothing is owed" while the coverage picture had changed underneath it.
		return new self(
			TRUE,
			self::outstanding($stored, $digests, $gated, $toolchain),
			$stored->unmeasured($digests),
			"the store describes this tree",
		);
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
	 * **A gated file the store classifies neither way is outstanding too**, and
	 * that case is reachable rather than theoretical: a file edited while the
	 * engine ran is deliberately left out of the store, because its verdict would
	 * belong to neither version of it. Without this it would be mentioned by the
	 * store — as something a test touches — and so not read as having appeared,
	 * while having no verdict to go stale either. Silently ungated, which is the
	 * failure that looks exactly like success (G9).
	 *
	 * **The question is asked about what is gated now, not about what the store
	 * holds.** A verdict survives the file being deleted or named in `exclude`,
	 * because merging a run's findings never removes what it said nothing about —
	 * so without this a deleted file would be reported outstanding on every run
	 * from now on, and every one of those runs would take a coverage pass to
	 * discover it has nothing to prove.
	 *
	 * @param Snapshot $stored
	 * @param array<string, non-empty-string> $digests
	 * @param list<string> $gated
	 * @param non-empty-string $toolchain
	 * @return list<string>
	 */
	private static function outstanding(
		Snapshot $stored,
		array $digests,
		array $gated,
		string $toolchain,
	): array {
		$wanted = array_flip($gated);

		// **No de-duplication**, and that is an invariant rather than an
		// oversight: `Snapshot::with()` takes anything newly proven out of the
		// unproven list and anything newly unproven out of the verdicts, so the
		// first two are disjoint — and the third adds only paths that are in
		// neither. Nothing can be in this list twice. The gate found the
		// `array_unique` here by removing it with no effect.
		$outstanding = [
			...self::stillUnproven($stored, $wanted),
			...self::moved($stored, $digests, $wanted, $toolchain),
			...self::unclassified($stored, $gated),
		];

		sort($outstanding);

		return $outstanding;
	}

	/**
	 * What an earlier run examined and could not prove, and is still mule's to
	 * answer for.
	 *
	 * @param Snapshot $stored
	 * @param array<string, int> $wanted
	 * @return list<string>
	 */
	private static function stillUnproven(Snapshot $stored, array $wanted): array {
		$unproven = [];

		foreach($stored->unproven as $path) {
			if(isset($wanted[$path])) {
				$unproven[] = $path;
			}
		}

		return $unproven;
	}

	/**
	 * The proven files whose key is no longer what it was.
	 *
	 * @param Snapshot $stored
	 * @param array<string, non-empty-string> $digests
	 * @param array<string, int> $wanted
	 * @param non-empty-string $toolchain
	 * @return list<string>
	 */
	private static function moved(Snapshot $stored, array $digests, array $wanted, string $toolchain): array {
		$moved = [];

		foreach($stored->verdicts as $path => $verdict) {
			if(isset($wanted[$path]) && !$verdict->holds(self::keyOf($stored, $path, $digests, $toolchain))) {
				$moved[] = $path;
			}
		}

		return $moved;
	}

	/**
	 * The gated files the store puts in neither state.
	 *
	 * @param Snapshot $stored
	 * @param list<string> $gated
	 * @return list<string>
	 */
	private static function unclassified(Snapshot $stored, array $gated): array {
		$unclassified = [];

		foreach($gated as $path) {
			if(!isset($stored->verdicts[$path]) && !in_array($path, $stored->unproven, TRUE)) {
				$unclassified[] = $path;
			}
		}

		return $unclassified;
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
