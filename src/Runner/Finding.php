<?php
declare(strict_types=1);

namespace Kristos80\Mule\Runner;

use Kristos80\Mule\Cache\Snapshot;
use Kristos80\Mule\Cache\Standing;

/**
 * What a run concluded: the store as it now stands, and what this run did to get
 * there.
 *
 * **Separate from the store on purpose.** "Everything is proven" and "this run
 * proved nothing because nothing was owed" are the same store and two different
 * things to tell somebody — and only one of them is worth the seconds it took.
 */
final readonly class Finding {

	/**
	 * @param Snapshot $store The store as it now stands, whole.
	 * @param Standing $standing What the store could say for itself before the run.
	 * @param list<string> $examined The files this run handed the engine and banked an answer for.
	 * @param list<string> $abandoned Files it asked about and then could not bank, because they were edited mid-run.
	 * @param bool $measured Whether anything was measured at all.
	 */
	private function __construct(
		public Snapshot $store,
		public Standing $standing,
		public array $examined,
		public array $abandoned,
		public bool $measured,
	) {}

	/**
	 * Nothing was owed, so nothing was measured.
	 *
	 * @param Snapshot $store
	 * @param Standing $standing
	 * @return self
	 */
	public static function settled(Snapshot $store, Standing $standing): self {
		return new self($store, $standing, [], [], FALSE);
	}

	/**
	 * @param Snapshot $store
	 * @param Standing $standing
	 * @param array<string, non-empty-string> $banked What the run asked about and banked.
	 * @param list<string> $abandoned
	 * @return self
	 */
	public static function proven(Snapshot $store, Standing $standing, array $banked, array $abandoned): self {
		return new self($store, $standing, array_keys($banked), $abandoned, TRUE);
	}

	/**
	 * Whether the project is wholly proven.
	 *
	 * **Read off the store rather than off this run**, because a file an earlier
	 * run could not prove is still not proven, and a run that happened to touch
	 * none of those files has not made the project green.
	 *
	 * **A file abandoned mid-run counts against it too.** It has no verdict in
	 * either direction — which is the honest record, and also not a project
	 * anybody should call proven. The next run picks it up.
	 *
	 * @return bool
	 */
	public function holds(): bool {
		return $this->store->unproven === [] && $this->abandoned === [];
	}

	/**
	 * The files this run re-proved: the ones it examined that the store now
	 * vouches for.
	 *
	 * @return list<string>
	 */
	public function proved(): array {
		$proved = [];

		foreach($this->examined as $path) {
			if(isset($this->store->verdicts[$path])) {
				$proved[] = $path;
			}
		}

		return $proved;
	}

	/**
	 * The files this run examined and could not prove — the escapes it found, as
	 * against the ones it inherited.
	 *
	 * @return list<string>
	 */
	public function escaped(): array {
		$escaped = [];

		foreach($this->examined as $path) {
			if(!isset($this->store->verdicts[$path])) {
				$escaped[] = $path;
			}
		}

		return $escaped;
	}
}
