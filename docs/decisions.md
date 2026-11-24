# Decisions

Why this repository is shaped the way it is. The first entries are the rewrite
itself: mule existed before this, as a working tool whose implementation carried
five mutation-gate suppressions, two mutation engines, a hand-rolled PSR-4
resolver, and a test suite that reached the disk in almost every file. Nothing
of it was published, so none of this is a migration.

## The external layer

**Every I/O call lives in `src/External`, behind an interface, in a wrapper
whose every statement is a call, an assignment, a return or a throw.** Anything
that decides — a condition that is not a failure guard, a default, a loop, a
ternary, `??`, `?->` — belongs in a tested class on the other side of the
interface.

The point is determinism. Given the same arguments, the same bytes and the same
exit codes from the processes it starts, mule makes the same decisions, builds
the same command line and writes the same store. That is provable without a disk
because the only code that touches one is those five wrappers, and it is
*testable* because the unit suite hands the rest of the program an array.

**Those five classes are the only code excluded from coverage and mutation**,
named file by file in both `phpunit.xml` and `infection.json5` so the two lists
stay visibly in step. They have no unit tests and are exercised by
`tests/Integration` against a real temporary directory and real child processes.

The old suite proved the opposite case: three of its tests `chmod`ed a file to
`0000` to reach an unreadable-file branch, and skipped when the user could not
be locked out of their own file — which is how CI runs. The 100 % gate therefore
measured one line set on a laptop and a different one in CI. There is no
`chmod` in this repository and no `markTestSkipped`.

## Where a wrapper throws and where it answers

A failure nothing can do anything about — a file that cannot be read, a digest
that cannot be taken — throws from the wrapper, so no caller carries a branch
for it and no test has to arrange a hostile filesystem to reach one.

A failure the caller must *decide* about is returned as a value.
`ClassesInterface::fileOf()` answers `string|false`, because a covering test
class the autoloader cannot place is a test whose edits would silently stop
invalidating anything, and the message that says so belongs with the code that
knows which source file was being keyed.

## No suppressions, and what replaced each one

Five `@infection-ignore-all` blocks and one global PHPStan softening are gone,
and nothing was added in their place:

| Was suppressed | Now |
|---|---|
| The number of random bytes in a scratch directory's name | `RandomInterface::token()` — a margin is not behaviour, so it lives behind the seam |
| `proc_open`'s file-descriptor numbers | inside `ShellProcesses` |
| `sort()` over the filesystem's own order | the entries arrive from a port, and the ordering is a pure decision a fake can feed unsorted input to |
| `(string)` on a decoded JSON key, twice | `Json\Decoded` decodes to objects and keys them by property name in one place; see below |
| `assert()` narrowing an `SplFileInfo` in a tree walk | the walk is one `iterator_to_array` in the wrapper; the ordering and the deletions are decisions |

**The JSON key is the interesting one.** Associative decoding hands back
`int|string` keys, so every place that reads one wants a `(string)` cast — and
Infection's `@cast` profile removes casts, PHP normalises numeric-string keys
anyway, so the mutant is equivalent and survives forever. That is how the first
suppression gets written. Decoding to objects and interpolating the property
name is the same conversion with no mutator pointed at it, in one method that
every test goes through.

**`Dom\XMLDocument::createFromString()` throws** on malformed XML where
`DOMDocument::loadXML()` returns `FALSE` and warns. So the coverage parsers have
no return guard, no branch, no mutant and nothing to suppress — one of the two
reasons the floor is PHP 8.4. (The other: `Dom\HTMLCollection` iterates as
`Dom\Element`, so reading an attribute needs no `instanceof` the analyser asked
for and no test for a case that cannot happen.)

## No custom static-analysis rules

The rules above are written in `CLAUDE.md` and checked in review. They are not
enforced by PHPStan rules of mine, because a rule of mine would need tests of
mine, and a mutation cache has no business shipping a lint suite. PHPStan runs
at level max with `tomasvotruba/cognitive-complexity` at the blueprint's 9 per
method and 40 per class — both of them dependencies, neither of them mine.

## The project's autoloader, not a PSR-4 resolver

Half of every key is a digest over the test files covering a source file, and a
coverage report names those tests as classes. The old implementation read
`composer.json` and reimplemented Composer's prefix resolution — 237 lines, and
227 more testing the reimplementation.

`vendor/autoload.php` *returns* the `ClassLoader` Composer built, and
`findFile()` resolves a class without loading it. `bin/mule` requires mule's own
autoloader first, so mule can boot, and the project's last, so the loader that
answers is the one that knows where the project's tests live — the same file
when mule is installed as a dev dependency, and a different one when mule is run
from somewhere else.

## One engine

Infection only. The Pest driver was the sole consumer of the file-to-class
direction of the PSR-4 map, it paid for coverage twice because Pest will not
take a report it is handed, and keeping two drivers in a tool that is "a wrapper
with caching" meant an `Engine` interface, a `Scope` value object and a `name()`
method that no production code ever called — it was kept alive by its own test.

## The store is replaced, not edited

`bank()` writes the merged store beside the old one and moves it over, so a
reader sees one file or the other and a crash leaves a scratch file rather than
half a store.

The old implementation held an exclusive lock and merged under it, which no
wrapper can express in a statement — so for one release two runs finishing in
the same instant meant the later one's file won and the earlier one's new
entries were lost. That was costing a run's work and nothing else, because a
missing entry is simply re-proven next time. **It is no longer the behaviour**:
the lock came back once it was clear a wrapper can express it after all, as a
closure. See "The merge happens under a lock, and the lock is not in the
repository" below; the replace-rather-edit decision here is unchanged and is
what makes the two halves separate.

## The walk up to `composer.json` is counted out in advance

A loop that ends by noticing it has reached the root ends *only* there, so the
mutant that removes the noticing runs forever and is killed by the clock. A
clock-killed mutant is the one verdict in a mutation report that says nothing
about the tests, and on this project it was the only one. A path has exactly as
many directories above it as it has separators, which is a number, and a loop
over a number cannot fail to end.

`ProjectTest` asserts the *sequence of directories mule asks about*, not only
where the walk ends: a bound that counted wrong is invisible in the answer,
because a walk that overshoots the root keeps asking about the root. That
assertion caught a real bug on the way in — the root candidate was being probed
as `//composer.json`, so a project at the filesystem root could never have been
found.

## Two keys in `mule.json`, and a fixed store path

`coverage` for a driver wrapper such as Herd's, `options` for the engine's own
flags, and `binary` where the engine is somewhere unusual. Everything else is
inferred, because a project has already declared it somewhere mule can read.

The store's path is not configurable: there is one of it, it is committed with
the project, and a path to it would buy a traversal check, two error messages
and the tests to go with them. The old implementation had all three.

`Json\Decoded` is the only place untyped data is narrowed. The old
implementation narrowed `mixed` in five places with six bespoke messages, and
27 % of its test methods were `testRejects…` cases — most of them proving that a
guard an analyser had asked for did indeed throw.

## The unit suite is `phpunit.xml` and nothing else

The integration suite has its own configuration, so the coverage command mule
infers for a project with no `mule.json` — a plain `./vendor/bin/phpunit` run —
produces the same report here as it does in CI. A `--testsuite` flag in one of
them and the committed store would miss on the other.

For the same reason **CI deletes `mule.json`** before running the gate: that
file holds this machine's `herd coverage` wrapper, and deleting it exercises
mule's inference rather than carrying a second copy of the configuration that
could drift from the first.

## The release flow is one workflow file

`release.yml` holds both jobs: `gates` on pull requests and on pushes to
`master`, `release` on a master push once the gates have passed. It replaced a
separate `ci.yml`, which would have meant either the same five gate steps
written twice — the drift this repository avoids everywhere else — or a release
that could be cut while the gate was red.

Modelled on `kristos80/hook`, with two deliberate differences.

**No `version` field in `composer.json`.** hook's release rewrites one with
`jq` on every release, and its own notes call that a wart worth deciding about:
`composer validate --strict` warns against the field for a published package,
because Packagist reads the tag. Nothing here writes it, so the plugin list is
one entry shorter and the manifest validates strictly.

**PHP 8.5 is an advisory matrix leg** (`continue-on-error`), and 8.4 blocks.
mule declares `php: ^8.4`, so the newer runtime is one consumers will use and
the leg is worth having — but no lock file is committed, so CI resolves the
newest dependencies inside the declared constraints on every run, and
`phpunit.xml` fails on deprecations. A dev dependency that starts emitting one
on 8.5 would otherwise hold up a release with no commit of ours. This is hook's
own reasoning about Sonar, applied to a runtime instead of a scanner.

**The mutation gate and the store check run on one leg only.** A mutation score
is a property of the code and its tests, not of the runtime, and the store is
keyed on file digests no PHP version changes. Running it on both legs would
prove the same thing twice and have two runs writing one store.

## The analyser is pinned to the versions composer.json declares

The first run of the release workflow found something neither local gate could:
`bin/mule` passed static analysis on the 8.4 leg and failed it on the 8.5 one,
for the same bytes — `Variable $argv might not be defined.`

Two separate problems, and both are fixed rather than ignored.

**`phpstan.neon` now pins `phpVersion` to `min: 80400, max: 80500`**, the whole
range `composer.json` declares, so the gate checks the code against every
version a consumer may run it on and gives the same verdict wherever the
analyser itself is running. An analysis whose result depends on the host is not
a gate, and it is the reason the matrix had two answers.

**And `bin/mule` reads `$argv ?? []`.** The pin did not reproduce the finding
locally, which is the tell: the complaint was never about the language version
but about `register_argc_argv` in the ini of the runtime the analyser was
executing on. The CLI SAPI always defines `$argv`, so the coalesce changes
nothing at run time — it removes a verdict that depended on an ini setting
nobody sets deliberately. It costs a dead branch in the composition root, which
is outside both gates, rather than a suppression inside them.

## A covering test's path is normalised before it reaches a key

Found by installing mule into the repository its design came from: the first run
reported **404 of that project's 610 files stale** against a store its own
previous implementation called entirely fresh. Diagnosed by computing both
keyings from one shared coverage report — source digests agreed on all 610, test
digests differed on 404.

**`ClassLoader::findFile()` answers with the directory as Composer's own
generated configuration spells it.** A psr-4 prefix mapped to `tests` comes back
as `{root}/vendor/composer/../../tests/Unit/FooTest.php`. The file is the right
one, so no content digest was ever wrong — but `Keying::coveringDigest()` hashes
`"{path}:{digest}"` lines, so **the path string is itself half of what the key
commits to**. Left as given, a store records paths no other reading of the same
repository can be compared with, and invalidates itself wholesale the day
`COMPOSER_VENDOR_DIR` moves.

`Project::relative()`'s docblock is where the assumption was written down, and it
was half true: *"every absolute path mule relativises comes from a coverage
report, which PHPUnit has already written resolved"*. Source paths do. Covering
test paths come from the autoloader, which is the one source that does not
answer resolved — and the containment guard could not catch it, because the root
prefix genuinely is there and `str_starts_with` passed.

**The fix applies `.` and `..` lexically, in `Project`, before the containment
check.** Three things decided it:

- **Not `realpath()` in `ComposerClasses::fileOf()`.** The external layer holds
  no decisions, and `$file === FALSE ? FALSE : realpath($file)` is a ternary
  there. Keeping the fix in a tested class also keeps it provable without a disk.
- **Lexical rather than resolved, so the documented symlink property survives.**
  The root is symlink-resolved and a report's paths are written resolved, so one
  that is not should still be *reported* rather than silently keying one file
  twice. Collapsing `..` does not resolve a symlink, so that still holds.
- **It strengthens the containment guard**, which is the part worth keeping in
  mind: `{root}/vendor/composer/../../../elsewhere` starts with the root *as a
  string* while naming a file outside it. Before this, such a path was recorded;
  now it is refused, and the message names the applied path, which is the file
  actually meant.

The cost is one store-wide invalidation, once: 16 of this repository's own 22
entries changed digest, and the next full run re-proved them. The benefit is that
a store written by mule now matches one written from normalised paths — which is
what let that project adopt it with its 613 banked verdicts intact, at **610 of
610 verified, 0 stale**.

## The merge happens under a lock, and the lock is not in the repository

The objection that dropped the lock was that holding one spans statements, and
`src/External` holds no decisions. **`underLock(string $path, Closure $critical)`
answers it**: the wrapper opens the file, takes the lock, calls what it was
handed, releases and returns — four calls, an assignment and two failure guards,
with every decision still in `Store`. One method rather than three, because a
lock taken and released by separate calls is one a caller can forget to release.

**Replacing a file atomically is not the same as merging into it safely**, which
is the distinction the first version missed. A rename gives a reader the old
store or the new one and never half of one. It says nothing about two runs that
both *read* the store before either wrote: each merges onto the bytes it read,
and the second rename drops the first one's entries. Both properties are wanted,
so both are here — the lock around the section, the rename inside it.

**The lock lives in the system temporary directory**, named
`mule-{sha256 of the store's absolute path}.lock`, and neither reason for that
is a preference:

- **`flock()` holds an inode, not a name.** A lock on the store itself is
  released-in-effect the moment this method renames a different file over it:
  the next process locks the *new* inode and walks straight into a section the
  first is still inside. Any lock file that lives beside the store and is
  replaced has the same hole; one nothing ever replaces does not.
- **The store's directory may not exist yet, and creating it is itself a race.**
  `bank()` used to check for `.mule` and create it *outside* any lock, so two
  runs on a fresh checkout both found it missing, both called `mkdir`, and the
  loser was handed `Cannot create the directory …` and died. So the creation had
  to move inside the critical section — which means the lock cannot be a file
  inside the directory being created.

Keying the name on the store's absolute path keeps two checkouts on one machine
out of each other's way while keeping two runs of the same one honest, and the
file is never removed: deleting it would reintroduce the inode race it exists to
avoid, and an empty file costs nothing. Nothing in a consuming repository has to
be ignored, which is the part that matters to a project that commits its store.

**It is proved by eight processes, not by an argument.**
`tests/Integration/BankTest` runs eight workers banking twenty-five verdicts
each against one store and requires all two hundred to survive. The number is
chosen so the test has teeth: one bank apiece would pass by luck. Measured with
the lock bypassed, **35 of 200 entries survived**; with it, 200. The `mkdir`
race above was found by that same test on its first run, having existed since
`bank()` was written.
