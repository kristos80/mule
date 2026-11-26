<?php
declare(strict_types=1);

namespace Kristos80\Mule\External;

/**
 * Which lines of a file could be executed at all.
 *
 * **Asked of the file on disk, which is the whole point.** A merged report is
 * assembled from shards written at different times, and the one failure a cache
 * like this can produce is a shard whose line numbers describe a version of the
 * file that no longer exists. Comparing the report against the parser is the only
 * way to notice, because the file still produces mutants and the engine still
 * reports a score (G3).
 */
interface LinesInterface {

	/**
	 * @param string $file Absolute.
	 * @return list<int> The line numbers, ascending.
	 */
	public function executableIn(string $file): array;
}
