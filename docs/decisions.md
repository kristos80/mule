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
wrapper can express in a statement. Two runs finishing in the same instant now
means the later one's file wins and the earlier one's new entries are lost —
which costs that run's work and nothing else, because a missing entry is simply
re-proven next time. That is the failure mode this cache is built around
everywhere else, too.

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
