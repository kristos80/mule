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

Half of every key is the test files covering a source file, and a coverage
report names those tests as classes. The old implementation read
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

## Sonar is a second opinion, analysed from CI so that it can see coverage

SonarCloud analyses this repository publicly. Two things about how, both
learned the hard way rather than read in a manual.

**Automatic analysis cannot produce coverage.** It reads the repository
server-side and never runs the suite, so the coverage metric simply does not
exist in that mode — the badge renders "Measure has not been found". That is
the whole reason `release.yml` has a `sonar` job: it runs the unit suite with
pcov, writes `coverage.xml`, and scans. The job is `continue-on-error` and
`release` does not depend on it, because `kristos80/hook` records an expired
`SONAR_TOKEN` returning 403 before a single file was analysed and blocking a
release whose own gates were green. The gates gate; Sonar comments.

**And automatic analysis ignores `sonar.issue.ignore.*`.** The first version of
`sonar-project.properties` silenced five rules that contradict this project's
style, and silenced nothing at all: the next analysis reported them exactly as
before. The findings did later go to zero, but not because of that file and not
because of anything done in the web interface — the project's PHP profile is
the built-in **Sonar way core** (97 rules), and none of the five are active in
it, `php:S112` included. So the clean board is SonarCloud's default, not this
repository's doing, and it would come back the day the project moved to the
full "Sonar way". The ignore block stays for that day, when a scanner will
honour it.

Worth being precise about one consequence: the thirty-five generic-exception
findings that prompted `src/Failure.php` were not active in the profile either.
The class earns its place on the argument below rather than on Sonar's.

## One exception type, so a bug does not read like a misconfiguration

`src/Failure.php` is a `final class Failure extends RuntimeException` with
nothing in it, and it is what every deliberate throw in `src/` raises.

SonarCloud asked for this thirty-five times — "define and throw a dedicated
exception instead of using a generic one" — and the rule would have been worth
declining if the only answer were ceremony. A hierarchy would have been: nothing
catches these selectively, because the command line catches everything. One
class is not, because it lets `Application` say which kind of wrong this is:

- a `Failure` → `mule: <message>`, which means the project, its configuration
  or its filesystem is not something mule can work with;
- anything else → `mule: <class>: <message> — that is a fault in mule rather
  than in your project, so please report it`.

Before it, both printed `mule: <message>` and exited 1, so a `TypeError` in
mule's own code was indistinguishable from a `mule.json` with a typo. The exit
status is still 1 either way: what changed is what the human reads. And because
`Failure` *is* a `RuntimeException`, every existing test that expected one kept
passing untouched.

## Three things a review found, and what each turned into

An outside review of v1.3.0 raised four concerns. Two were already answered in
the code, one was right and larger than it looked, and one was right and cheap.

**The engine's configuration is now watched, and the hole was real.** Only
`composer.json` was. Measured before fixing it: adding `@equal` to the mutator
set in `infection.json5` — a whole new class of mutants — left mule answering
*"30 of 30 verified, nothing to mutate"* about a gate that was now measuring
something materially different. Unlike `mule.json`, these files are committed
and byte-identical everywhere, so there is no portability argument against
watching them. `infection.json`, `.json5` and their `.dist` variants are all
watched where they exist.

**The PHP version is still not fingerprinted, deliberately.** The review
mentioned it without weighing the cost: a laptop on 8.4 and a CI on 8.5 would
re-prove everything on every push, which destroys the property the committed
store exists for. It stays a documented limit, beside timeouts.

**Normalising the engine's options out of `mule.json` was declined.** It would
mean mule parsing Infection's flags to judge which ones change meaning, and CI
deletes that file anyway, so the fingerprint would differ between machines.

**Nothing is banked whose bytes moved while the engine ran.** The gate keys the
tree, then spends minutes mutating it; a file saved in between was proven as
one thing and would have been recorded as another. The files about to be banked
are re-hashed first, and any that moved are dropped and said out loud. It is a
handful of digests after a run measured in minutes.

**And mule now says when a report cannot describe a dependency.** Where every
test is credited with exactly one file, no change can ever mark another file
stale, and the narrowing looks exactly as trustworthy as it would on a report
that had measured everything. That is what `#[CoversClass]` does, and it is the
single biggest risk in installing mule into an existing project — so it is a
line of output and an installation note, not a silent property. Mule does not
touch anyone's tests or configuration to fix it.

## A coverage run can be narrowed, and the rule for it took two goes

`{tests}` in the coverage command expands in place to the tests a run needs.
Its absence is the opt-out, because narrowing a test run assumes tests do not
depend on one another having run, which is not mule's assumption to make.

**The first rule was wrong, and dogfooding caught it.** It asked for every test
that ran something *changed* — and the run came back reporting ten files as not
covered in full. A file being re-keyed is only as covered as the report says
covers it, so running some of its tests makes the rest of it look unreached.
The rule is every test that runs anything **outstanding**: stale or unproven,
the same set the engine is about to mutate. The closure argument still holds —
a test that runs nothing outstanding cannot have changed its own footprint,
because for it to have changed, something it runs would have had to change,
which would have made that file outstanding.

**A partial report still names every file in the project**, most with no
covering test, which is how it says "I did not run those". Reading it whole
would record them as uncovered and quietly destroy good verdicts, so `Keying`
takes the list of paths a report is authoritative about and ignores the rest —
and watches nothing, since what the whole tree looks like is not something a
partial report can say. A canary proved the test for this: the first version
passed with the partiality removed, because its fixture made the untouched file
look trivially covered rather than uncovered.

**It buys nothing on this repository.** Editing one file leaves 23 of 30 files
outstanding — the broad in-memory tests here touch sixteen source files apiece
— so the subset is the whole suite. The mechanism is for repositories whose
tests are narrow; kept in `mule.json` here as dogfooding rather than as a
speed-up.

## Staleness is answered by hashing, and coverage only runs when it must

Deciding what is stale used to require generating a coverage report, which
made the cheap question depend on the expensive answer: every run paid the
whole suite to be told it had nothing to do. Now the store records a digest for
every file the project autoloads, so the question is a few hundred hashes.

**Measured here: `mule new` with nothing changed is 0.05 s against the 2.4 s its
coverage run costs.** Hashing the universe is ~13 ms for 56 files, so a
thousand-file repository pays about a quarter of a second to skip a minute.

`Freshness` refuses rather than guesses. It answers only when the store still
describes the tree: nothing it recorded has vanished, nothing it never recorded
has appeared, every watched file still hashes the same, and so does every file
it could not prove. Any of those and the answer is "run coverage" — the only
case it answers for itself is the one it can be certain of. Which is also why
what each test runs may be read from the store rather than re-measured: it is
only ever read where nothing that test could run has moved.

Two things found while building it, both of which would have been quiet bugs:

- **`getPrefixesPsr4()` returns every registered prefix, dependencies
  included.** The first working version recorded 1,500 digests, most of them
  `vendor/`. A store like that is invalidated by any `composer install` and
  differs between machines — it would have destroyed the one property the
  committed store exists for. `Universe` keeps only what the project owns:
  inside the root, outside `vendor/`.
- **`mule.json` is not watched**, although it changes what a run does. It is
  the one file whose purpose is to differ between machines — the `herd
  coverage` wrapper here, nothing on CI, which is why CI deletes it — so
  watching it would make the committed store unusable on CI. Verified that the
  alternative does not work: a coverage driver enabled by `herd coverage` does
  **not** survive into a grandchild process, so the wrapper cannot move outside
  the command mule runs. The cost is stated in the code: change the coverage
  command and nothing is invalidated, so run `mule full` after.

## The store records what each test runs, not a digest of it

The store used to keep, per source file, a digest over the files covering it.
That answers "has anything about my tests changed" and nothing else, and it
left a hole: editing `Verdicts.php` marked `Verdicts.php` stale and left
`Gate.php` verified, although `GateTest` runs straight through `Verdicts`, so a
`Gate` mutant could start surviving with nothing marked stale at all.

So the store keeps a list instead — per test file, its digest and every source
file the report credits it with — and a file is stale when its own bytes move,
when a test that runs it moves, or when anything else that test runs moves.

**Measured here after the change:** editing `src/Cache/Touched.php` marks 18 of
25 files stale, where the old key marked 1. That is the cost of the stronger
claim, and it is the honest one: those eighteen really are proven by tests that
execute the file that changed. How wide it spreads is a property of the test
suite rather than of mule — `GateTest` alone is credited with 16 source files,
because it drives the whole program over in-memory ports.

Three implementation notes worth keeping, all of them forced by the mutation
gate rather than by taste:

- **Set markers are never `TRUE`.** `$set[$path] = TRUE` is unkillable when
  only `isset()` reads it, because `FALSE` behaves identically. The sets here
  are built as lists and flipped, so dropping the flip turns every lookup false
  and a test notices.
- **Deduplication is by key, and the keys are sorted before `array_keys()`.**
  `array_unique()` paired with `array_values()` leaves the second call
  unobservable; keying by path makes uniqueness inherent, and unwrapping the
  `array_keys()` turns the list into a map, which the store then writes as a
  JSON object instead of an array — visible in a golden assertion.
- **A store written before these sections existed reads as empty**, so the next
  run proves everything once. Bytes that are not a JSON object at all still
  stop the run; an older shape is not damage.

## No covers metadata, because the cache keys on what the report says

`#[CoversClass]` is gone from every test here, and `requireCoverageMetadata`
with it. The reason is not style: a coverage report credits a test with what it
*declares*, and mule's key is built from that credit.

Measured before the change, on one test: `GateTest` credited with **1** file
with the attribute, **14** without it — the same test, the same assertions.
Across the suite it was 1.00 files per test class, maximum 1, which is what an
attributed report looks like.

What those thirteen hidden files cost is a real hole: editing `Verdicts.php`
left `Gate.php` verified, although `GateTest` runs straight through `Verdicts`,
so a `Gate` mutant could start surviving with nothing marked stale. `mule new`
would pass where `mule full` failed.

There is no way to have both. PHPUnit respects the attribute unconditionally —
`requireCoverageMetadata="false"` changes nothing, verified by experiment — and
`#[UsesClass]` does not help either: `php-code-coverage`'s
`applyCoversAndUsesFilter()` keeps only `linesToBeCovered` and passes
`linesToBeUsed` to the unintentionally-covered check alone. The remaining routes
— copying the test tree and stripping the attributes, or a second instrumented
pass — break `__DIR__`-relative tests or cost a whole extra suite run.

**The price paid for it:** attribution was also what made a method with no test
of its own show up as uncovered — that is how `Summary::crashed()` was caught.
With complete attribution, a method exercised from any test counts as covered,
so that check is now a human one. Worth it: a cache that quietly answers
"verified" about a file whose behaviour has changed is a worse failure than a
method whose test lives in the wrong file.

## A method is only covered by its own class's test

Adding `Summary::crashed()` above dropped the coverage gate to 266 of 267
statements while the test asserting its exact output passed — which looks
impossible until you remember that coverage here is attributed by
`#[CoversClass]`. `ApplicationTest` exercises the method but credits only
`Application`, and `SummaryTest` is what credits `Summary`, so a method tested
only from somewhere else reads as uncovered. The mutation gate would then have
counted its mutants as uncovered too, failing for a reason that looks like a
lie.

Worth knowing because the failure is confusing rather than obvious: a canary
string proved the method really did run before the actual cause turned up. The
fix is always the same — give the new method a case in its own class's test.

**No longer true as written**, since the entry above removed covers metadata
altogether: a method exercised from anywhere now counts as covered, so this
particular gap would pass both gates today. The habit it describes is still the
right one; it is simply no longer enforced.

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

## A verdict is stale when the gate is redefined, and "watched" was not enough

`infection.json5` has been recorded since the release before this one, and that
was only half the fix.

The hole was found by experiment: adding `@equal` to the mutator list left mule
reporting **"30 of 30 verified, nothing to mutate"** about a gate that had just
been handed a new class of mutant to kill. Recording the file fixed what that
experiment measured — `Freshness` now sees a digest move and returns
`Standing::unusable()`, so the fast path stops answering and coverage runs.

**And then the run still mutated nothing.** A review of the released version
pointed out the half that was left, and reading the code confirmed it before
anything was changed:

- `Freshness` used the watched digests to decide whether the store *describes
  the tree* — the coverage half.
- `Snapshot::staleAgainst()` decided which verdicts survive, and it compared
  `verified` and `tests` only. No source file had moved, so it returned `[]`,
  and a narrowed run was told its scope was empty.

So the configuration was watched for the purpose of invalidating *coverage
reuse* and never for invalidating *verdict reuse*, which is the one that
matters. The test suite had the behaviour pinned down as correct, in a case
called `testRunsCoverageButMutatesNothingWhenOnlyAWatchedFileChanged` with a
docblock explaining why it was fine. It was not fine.

**The fix is a section of its own.** `watched` and `configuration` are now two
sections in the store rather than one, because the two mean different things
when a digest moves:

| | |
|---|---|
| `watched` — an autoloaded file no test reached | the store can no longer claim to describe the tree, so coverage runs again. **No verdict is unproven**: nothing executed that file, so no verdict depended on it. |
| `configuration` — `composer.json`, `infection.json5` and its siblings | every verdict in the store answers a question nobody is asking any more. **All of them go.** |

`Snapshot::supersedes()` is the whole of it: a snapshot whose configuration
differs from the stored one returns every path it knows as stale. Three things
are deliberate about that.

- **Everything, rather than working out what the change could reach.** Which
  mutants a newly enabled profile adds to which file is the engine's business.
  Guessing it produces a false verdict; re-proving a project whose gate was
  just redefined is something somebody did on purpose and will not be surprised
  by.
- **A snapshot with no configuration recorded supersedes nothing.** That is the
  partial report a narrowed run reads, which cannot see the whole tree and does
  not pretend to. It is only ever read on a run whose store was usable, and a
  usable store has already had its configuration compared — so the guard is not
  a loophole.
- **Banking a narrowed run carries the stored configuration forward.** The
  partial report has none, and letting that empty section win would wipe the
  record on every incremental run, which the next run reads as "measured under
  no configuration" and answers by re-proving everything. One of the two
  mutants that escaped the first green suite was exactly this, and
  `testKeepsTheRecordedConfigurationWhenBankingANarrowedRun` kills it.

**A store written by the previous release reads as having no configuration**,
which needs no migration code and no format version — but getting that right
took a second pass, and the first version of this entry claimed something
false. `supersedes()` lives in `staleAgainst()`, which is only reached *after*
a coverage run, so the fast path never saw it: `Freshness` compared the store's
own recorded digests against the tree, and an empty section matched trivially.
Measured on a 610-file project, a previous-release store answered "610 of 610
files verified, 0 stale" in 0.6 s and would have gone on doing so for good.

The same gap hid a second one. Nothing could notice a declared file that
*appeared*: the store can only be checked for digests it already holds, so a
project that gained an `infection.json5` it never had would keep answering from
verdicts proven without one — in the new format as much as the old.

So the declared set is now asked of the **tree**, by `Cache\Declared`, and
`Freshness` requires the store's section to equal it exactly. That one
comparison covers a declaration changing, appearing, vanishing, and a store
that records none at all. It is also the only part of the check that does not
depend on the store to know what is worth looking at, which is what let the
appearance case exist in the first place. The two sections are also disjoint by
construction in `Snapshot::of()`, so an old store that kept `composer.json`
under `watched` does not carry it in both forever.

**What is still deliberately not keyed**, and this is the line worth being
explicit about rather than leaving to be discovered: the PHP version, and the
installed versions of PHPUnit, Infection and the coverage driver. Keying them
would end the property the cache exists for — a laptop on 8.4 and CI on 8.5
would share nothing, and a `composer update` would discard every verdict. What
is *committed* is keyed; what is *installed* is not. `mule.json` stays out for
the same reason in reverse: it is the one file meant to differ per machine, and
CI deletes it.

## A deleted file used to disable the cache permanently

Found while writing the README section on recovery, by checking a claim rather
than trusting it. The sentence being checked was "`mule full` is the rebuild",
and it was not true.

The store is **merged into, never replaced**, so that a run which measured part
of the project cannot erase the rest. The consequence nobody had followed
through: a deleted file's entry has nothing to remove it. And a store that
records a file the tree does not have is precisely what `Freshness::digestsOf()`
refuses to answer past — it returns `NULL`, and the standing is unusable.

So deleting one file put mule in a state it could not leave:

1. `src/Old.php` is deleted.
2. Every run from then on finds a recorded file missing and declares the store
   unusable, so the fast path is dead and coverage runs every time.
3. `mule full` does not fix it. It mutates everything, banks, and the merge
   carries the dead entry straight into the new store.
4. Repeat forever. The only way out was to hand-edit `.mule/verified.json` or
   delete it.

Measured, not argued: `testStillAnswersFromTheStoreAfterAFileIsDeleted` failed
with the store running coverage on a tree where nothing had changed since the
previous full run.

**The fix is in the one place that writes the store.** `Store::pruned()` drops
every entry whose file is no longer there, from every section, just before
the bytes are written — so the repair happens on any run that banks anything,
and `mule full` really is the rebuild. It costs one `exists()` per entry,
charged to a run that has just spent minutes in the engine.

Pruning at *read* time would have been wrong: a read that quietly dropped
entries would make the store's claims depend on when they were asked for, and
the file on disk would still be the broken one. Pruning in `merged()` would
have been wrong too — `merged()` has no filesystem and must not acquire one,
since its whole job is to combine two snapshots without reference to a tree.

It also means the two review findings and this one share a shape worth naming:
each was a rule that was correct about the case it was written for and silent
about a neighbouring one. Watching the configuration without unproving
verdicts; re-hashing sources without re-hashing tests; merging to preserve
partial runs without ever removing anything.

## Coverage is reported, which means a sixth section rather than a passthrough

Asked for directly: the point of mule is that a project runs one tool, and a
tool that holds a coverage report and says nothing about it sends people back to
a second one.

The cheap answer was to let the test runner say it — add `--coverage-text` to
the coverage command and let the bytes through, exactly as Infection's own report
is let through. It was rejected for a reason specific to this cache: **the paths
where mule is worth using are the paths where no coverage run happens.**
`Gate::recalled()` and `Gate::settled()` answer from the store and start no
process, so a flag on a command nobody runs prints nothing. It would also only
have reached projects on the inferred command — a declared `coverage` is run as
given, and both this repository and the project mule was written for declare one
to get a coverage driver.

The numbers were already being parsed and thrown away. `CoveredFile` read the
per-file totals and only ever answered `isFullyCovered()` with them, so what was
needed was to stop discarding them: they became a `Coverage\Reach`, which is the
same value for one file and for a whole project because adding two of them is
all the aggregation there is.

**Recorded per file, in a section of its own.** Three alternatives were weighed:

- *A number in the existing `verified` entries.* Nicer to read in a diff, and it
  would have made `Snapshot::verified` an object per path rather than a digest
  per path — rippling through `moved()`, `staleAgainst()`, `digests()`, `only()`
  and `Freshness`. Worse, `Decoded::number()` is strict by design, so a required
  key inside an existing entry would make every store already in the world a
  hard failure rather than an upgrade.
- *A project total.* Cannot survive a narrowed run: the run measures a handful
  of files and would report those files' coverage as the project's.
- *The coverage report's own index totals.* Wrong for the same reason and less
  obviously so. PHPUnit's `index.xml` totals every source file in the tree
  against only the tests that ran, so a subset run's index reports a project
  that has mostly stopped being covered.

So `coverage` is a sixth store section, keyed by path, following the precedent
`configuration` set: an absent section reads as empty, and an old store reports
its file counts and says nothing about lines until a run measures some. The
alternative — treating a store with no line counts as not describing the tree —
would spend a coverage pass, and for a settled store the whole engine, to obtain
a number that is only ever printed.

**The aggregate is the store's picture with the run's written over it.** In
`Gate::report()` that is `$stored->merged($reading->snapshot)->reach()` wherever
the store is usable, which is the same arithmetic the file count already used
and for the same reason. `testReportsTheWholeProjectsCoverageAfterANarrowedRun`
is what holds it: twenty of the project's twenty-one lines are in a file the
narrowed run never re-measures.

**The proportion is integer arithmetic, rounded down.** `intdiv($executed *
1000, $executable)` rather than a float, because `0.29 * 1000` is `289.999…` in
binary and a project at 29 % should not be reported at 28.9 %. Down rather than
nearest, so that a project one line short of complete cannot read as 100 %.

What this deliberately does **not** do is report mutation scores or escaped
mutants. Mule reads the engine's exit code and nothing else, and a verdict in
this store means "every mutant in this file died" — a proof, which is why
skipping the file is sound. An MSI below 100 is a measurement: cacheable, but
never something a later run could skip work on the strength of. Reporting one
would be a second kind of entry with different rules, and the gate is pinned at
`--min-msi=100` anyway, so a passing run has no survivors to name.

## The gate's thresholds are refused in `mule.json`, and `--with-uncovered` is not

Asked while working out whether mule could support arbitrary coverage and
mutation thresholds. The answer separated into three parts, and only one of them
was mule's business.

**A coverage threshold is not.** Mule reports coverage and gates on none of it.
The rule the cache depends on is per-file and a different question from any
project threshold: a file is bankable when the engine saw all of it, because an
unreached line produces no mutant and a clean exit therefore says nothing about
it. That holds at any project-level target.

**A mutation threshold below 100 cannot work, for an arithmetic reason rather
than a strict one.** A score is an aggregate over the run's scope; skipping a
file needs a fact about that file. At 100 %, exit 0 decomposes — every mutant in
every file died — so a per-file record is justified and a narrowed run asks the
same question as a whole one. At 90 % it does not: exit 0 is compatible with one
file at 100 % and another at 60 %, and a narrowed run over one changed file would
demand 90 % of that file while a full run demands 90 % of everything. The two
verbs then disagree in *both* directions, which destroys the only property the
cache sells. Supporting it means storing killed-and-total per file and
recomputing the project figure — a measurement cache rather than a proof cache,
needing per-file numbers from an engine whose CLI does not offer them.

**Which made an existing hole worth closing.** `Infection::command()` spreads a
project's `options` in *after* mule's own flags, and the last occurrence of an
option is the one Infection honours — verified rather than assumed, with
`--min-msi=101 --min-msi=0` exiting 0 and the reverse order exiting 1. So
`"options": ["--min-msi=90"]` silently replaced the gate the entire store is
keyed on, and mule went on recording "every mutant in this file died" about
files where some had lived. Nothing warned, and the store is committed, so the
next machine inherited the claim with nothing left to say it had never been
true.

`Configuration::optionsOf()` now refuses both threshold flags by name, at
configuration load, before a coverage run is spent. **By name, whatever follows
them**: Infection accepts `--min-msi 90` as two arguments as readily as
`--min-msi=90`, so matching on the value would miss half the ways of writing it.
A project that passes `--min-msi=100` redundantly is told to delete a line,
which is a better trade than a parser for a number mule intends to ignore.

**Refused, not warned about**, because the consequence of an unread warning is a
committed file full of verdicts nothing proved. Mule has one channel for a
configuration it cannot work with, and this is a `Failure` like every other.

**`--with-uncovered` is accepted**, which was the correction to a first draft
that refused it too. It is not unsound: at full coverage it generates nothing
extra and is a no-op; below full coverage its uncovered mutants enter the
denominator, the run fails, and nothing is banked — strict, self-defeating if
unintended, and never false. "My gate fails if anything is uncovered" is a
legitimate thing to want, and mule already prints which files are not covered in
full, so the failure explains itself. Refusing a flag that is merely strict
would have been mule being opinionated about something that is not its
invariant.

Worth recording from the same session, because it had been asserted in this
repository and never checked: **Infection does not mutate uncovered code by
default.** Measured on one file — default, 9 mutants and 0 uncovered; with
`--with-uncovered`, 17 mutants and 8 uncovered. So the README's claim that an
unreached line produces no mutant is correct, and the reason mule's own
`unproven` logic exists is a second, independent guard rather than the only one.
Infection 0.32 also has no `--logger-json` option at all, which closes the
partial-banking path more completely than the argument given for it.

## mule.json became mule's own vocabulary, and the engine stopped being visible

Asked directly, and the reasoning was the user's rather than mine: if the point
is that a project runs one tool, then a configuration file naming PHPUnit's flags
and Infection's schema keys is two tools wearing one coat. An earlier draft of
this change had `mule.json` carry an opaque `infection` object passed straight
through; it was rejected for exactly that reason, and rightly.

**What the reshape rests on, measured rather than assumed:**

- **The engine cannot be driven by flags alone.** It has no option for the
  directories to mutate, and with no configuration file it tries to generate one
  interactively and aborts under `--no-interaction`. So mule had *already* been
  requiring every project to hand-write the one file it claimed to infer around.
- **A configuration file is read from anywhere, and its relative paths resolve
  against its own directory.** A generated config in `/tmp` sent the engine
  looking for `phpunit.xml` in `/tmp`. Hence `Engine\Declaration` writing
  absolute `source.directories` and an explicit `phpUnit.configDir`.
- **The engine and a current PHPUnit share a `composer.json` perfectly well.**
  This repository had `infection/infection ^0.32` and `phpunit/phpunit ^12.0` in
  `require-dev` with one `sebastian/diff` between them. The README's standing
  claim that they "cannot always share" was stale, and it was the only argument
  for treating the engine as a command found on a path. It moved to `require`,
  which is also what makes its version pinnable.
- **`source.excludes` is relative to each source root and refuses an empty
  list.** So `exclude` is project-relative in mule's vocabulary and translated,
  and the key is omitted entirely when there is nothing in it.

**`exclude` drives both halves, and that is the point of it.** Excluded from
mutation alone, a file still arrives in the coverage report, is still covered in
full, and is still banked — recording that every mutant in it died when nothing
ever made one. `Keying` therefore drops excluded paths from the report as it
reads it, so such a file is not in mule's universe at all: not verified, not
watched, not counted in the lines covered. This is the duplication `CLAUDE.md`
used to manage by asking for two lists kept "visibly in step"; one list is
better than a rule about two.

**Unknown keys are refused.** Mule owns the whole vocabulary, so a key it does
not recognise is a mistake every time. That replaced a narrower guard written
days earlier, which had refused `--min-msi` inside a passthrough `options` array
— a guard that is simply unnecessary once there is no passthrough to guard.

**`min-coverage` is mule's, and `min-mutation` cannot be.** Coverage is a number
mule already measures, sums and caches, so asserting a floor on it changes
nothing about what gets banked and removes the last reason to run a second tool;
this repository deleted a `php -r` clover-parsing composer script for it. A
mutation threshold is a different kind of thing: mule reads the engine's *exit
code* and never its report, so there is no figure to compare against — and the
full score is what makes that exit code decompose into a per-file fact at all.

**Banking happens before the coverage assertion, never instead of it.** The
mutation work was done and proven; discarding it because a separate assertion
failed would mean paying for it again next run. Put the other way round: a
failing `min-coverage` still leaves the store richer than it found it.

**The runner is handed mule's own interpreter *and its ini*.** This was the one
thing that nearly sank the model. The intended usage is to wrap mule — `herd
coverage bin/mule new` — so that whatever enables a coverage driver is in force
for mule's process. But measured on this machine: under that wrapper the parent
loads `…/config/php/84/debug/debug.ini` and has Xdebug, and a child started as
plain `PHP_BINARY` loads no ini and has none, so mule's coverage run found no
driver. The same binary, a different configuration. `PHP_BINARY -c <the parent's
ini>` restores it, which is why `ProcessesInterface::iniFile()` exists — the raw
`string|false` returned rather than resolved, like `ClassesInterface::fileOf()`,
because "there is no ini" is a case the command line has to decide about.

**CI stopped deleting anything.** It used to `rm mule.json` before the gate,
because the committed file named a wrapper CI does not have; the workflow
comment made a virtue of it ("one more thing proved"). With nothing
machine-local left in the file there is nothing to remove, and the inference path
is now exercised by *absence* — the way a consumer actually meets it — rather
than by a deletion only this repository performs.

**What it cost:** every setting worth having must be given a name here, and there
is no escape hatch. A project wanting `maxTimeouts` needs mule to grow a key.
That is the deliberate trade for a vocabulary mule can keep stable and make
promises about, and it is the opposite of the trade the rejected passthrough
made.

## Neither tool is behind an interface, and what was done instead

Asked for during review: Infection and PHPUnit should be proxied by interfaces
so they can be swapped later and so their dependents can be tested without them.
The second half turned out to be already true, and the first half was answered
by injection rather than by an interface — but the question found a real flaw on
the way.

**Nothing in the unit suite ever runs either program.** The seam that matters is
`ProcessesInterface`, which is an interface and is faked by
`tests/Support/CoveringProcesses`: it writes a coverage report where it is told
and records what the engine was asked. `Engine\Infection` and `Runner\Measurement`
are not proxies to anything — they are pure functions returning `list<string>`,
and `Engine\Declaration` returns a string of JSON. There is nothing to stub: a
test states the command or document it expects and compares it. The 349 unit
tests run with no PHPUnit binary, no engine binary and no disk.

**An interface over them would be a false seam**, which is worse than none
because it reads as insurance. It would be shaped entirely around this engine —
`command(Run, ?array): list<string>` plus a configuration document in the
engine's own schema — so a genuinely different engine, one needing no config
file or scoping differently, would not fit it anyway. The abstraction has also
been here before: see "One engine" above, where an `Engine` interface, a `Scope`
value object and a `name()` method no production code called were removed for
being kept alive by their own tests. And `CLAUDE.md` is explicit that an
interface in this repository wraps **I/O**; these two perform none.

**The flaw the question did find** was asymmetry, not missing abstraction.
`Gate` built the runner's command line inline — `--coverage-xml`,
`--log-junit`, `--testsuite` sat next to the decision about *what* was stale,
while the engine's spelling lived in a class of its own — and it constructed
`new Declaration(...)` inside the method that used it, a dependency the
composition root never wired and no test could replace.

So the runner's command line became `Runner\Measurement`, the sibling of
`Engine\Infection`, and both it and `Declaration` are constructor dependencies
wired in `Application`. The gate now holds two command builders and knows
neither tool's vocabulary; swapping either is one line in the composition root.
That the whole suite passed on the extraction with only the wiring changed is
the evidence it was behaviour-preserving.

**It covers both runners deliberately.** Pest takes the arguments PHPUnit takes,
so what varies is the binary — which `Configuration` resolves — and not the
spelling. Naming the class for the job rather than for either tool is what keeps
that true, and is the same reason `mule.json` names no tool either.

**The full score moved off the command line and into that document**, caught in
the same review. `--min-msi=100 --min-covered-msi=100` were flags; `minMsi` and
`minCoveredMsi` are schema keys, and a threshold is what the gate *means* rather
than what a particular run is doing. What stayed on the command line is what
varies per run: the scope, the coverage directory, and a narrowed run's tolerance
for a filter that matched nothing. Verified rather than assumed, because moving a
threshold is exactly the change that could stop a gate failing silently: with a
known-escaping mutant present the run exits 1 and reports the same error from the
config as it did from the flags.

**`minCoveredMsi` is not `min-coverage`**, however alike they read, and the
resemblance is worth a sentence because it invites the mistake. The engine's key
is a mutation score over the code the tests reach. Mule's key is the proportion
of lines they reach at all, asserted by mule from the coverage report it already
holds — it is never passed to the engine, which is asked no question about
coverage at all.

**An external review found a real hole in `exclude`, and it was the same hole
again.** The engine's collector builds
`Finder::create()->in($sourceDirectories)->exclude($excludes)->notPath($excludes)`,
and Symfony's finder applies those relative to *each* directory it was given. So
a project with `source: ["src", "lib"]` excluding `lib/Foo.php` hands the engine
`Foo.php`, which also drops `src/Foo.php` — while mule's own check matched the
exact project-relative path and let `src/Foo.php` through. Mutated by nothing,
present in the coverage report, covered in full, banked as proven: the very trap
`exclude` was introduced to close, reappearing the moment there are two source
roots.

Fixed by making mule ask the question in the engine's terms rather than its own.
`Configuration::excludes()` compares a path as it reads *inside its source root*,
so a file the engine will not mutate is a file mule will not read a verdict for,
whichever root the exclusion was written under. The consequence is adopted rather
than fought: excluding `lib/Generated` excludes `src/Generated` too, because that
is what the engine's own list means, and a project wanting one and not the other
has to name them differently. Documented in the README's key table instead of
guarded against. Two regression tests, both confirmed to fail without the fix.

The review's other two points were checked and answered rather than taken on
faith. The dependency surface is real but narrower than feared — Infection's
`sebastian/diff ^4.0 || … || ^8.0` spans PHPUnit 9 through 12, so nothing there
conflicts; what it does impose is Symfony `^6.4` console, filesystem and finder,
`nikic/php-parser ^5.6.2` and `justinrainbow/json-schema ^6.0`, which the
README now names along with the way out. And the README's `json5` example with
explanatory comments was a trap: a commented `mule.json` fails, and it failed
with "mule needs it to be a JSON object" — a message pointing nowhere near the
syntax. The example is plain JSON with the annotations beside it now, and
unparseable bytes say so distinctly from valid JSON that is not an object.


## The mutators are an argument, because the engine's schema is narrower than the engine

Two defects in the first release of the configuration rework, both found by
running it against the project mule was written for rather than against itself.

**`mutators` could name a family the engine would refuse.** Mule's list of
families was written from the engine's `ProfileList::ALL_PROFILES`, which is the
command line's view. The *configuration schema* is narrower: it sets
`additionalProperties: false` and defines eighteen profiles where the engine
honours nineteen. `@nullify` is in the engine and not in the schema, so once the
configuration was a document mule writes rather than flags mule passes,
`"mutators": {"nullify": true}` produced a file the engine rejected outright —
`[mutators] The property @nullify is not defined and the definition does not
allow additional properties`. Verified by handing the engine exactly the document
mule would have written.

**And it could not name a single mutator at all.** hermes-2 turns on `ArrayFind`
and `ArrayFindKey` individually, precisely because the engine ships no profile
its schema accepts for them. Mule's vocabulary had families and nothing else, so
that project's `mule.json` — empty, since every key is optional — silently gated
on less than its `infection.json5` had. A tool that cannot express its own
flagship consumer's gate is not worth the purity.

Both are the same mistake and have one fix: **name the mutators as an argument**,
which is validated by the engine rather than by a schema describing it. Verified
to accept `@default,@identical,@nullify,ArrayFind,ArrayFindKey` in one go.
`mule.json` now takes either vocabulary in its `mutators` map, told apart by
case — lower case is one of mule's families and is checked against the list;
anything else is one of the engine's own mutators, which are PascalCase without
exception, and is passed through. That is the one place the engine's vocabulary
shows, and it is admitted rather than worked around because there is no mule word
for a single mutator and no family that covers one.

It is also the first real cost of having no passthrough, which was the trade
accepted knowingly when `mule.json` became mule's own vocabulary. The lesson is
narrower than "add an escape hatch": a list copied from one of a dependency's
two surfaces will be wrong about the other.

**PHPUnit joined `require` alongside the engine.** A consumer installing mule
gets both tools it drives, and neither has a key in `mule.json` to point at one.
The cost is a narrower compatibility surface rather than a conflict: the two
tools resolve together without trouble — PHPUnit 13.1.14 against Infection
0.32.7, sharing `sebastian/diff 8.3.0`, with mule's whole suite, coverage and
mutation gate green on it — but `^12.0 || ^13.0` means a project on PHPUnit 10 or
11 cannot install mule as a dev dependency and must install it as a tool of its
own. The store is keyed on file digests and does not care where mule came from,
so that route costs nothing but the mention.

One consequence of the engine being a dependency rather than a command, found by
installing mule into a fresh project rather than by reasoning about it: Infection
ships a Composer plugin, so a consumer must allow
`infection/extension-installer` or a non-interactive `composer install` stops
before it writes a vendor directory. Dependency *resolution* is fine — fifty-nine
packages, no conflict — which is why this does not look like a dependency
problem and is not one. The README carries the line to add.

## The breaking-change marker in a subject never worked, and nothing noticed

v3.0.0 was due from a merged `feat!:` and no version was cut at all. The log did
not say "no breaking change"; it said **"The commit should not trigger a
release"** — the analyser had not seen a `feat` either.

`@semantic-release/commit-analyzer` defaults to the
`conventional-changelog-angular` preset, whose `headerPattern` is
`/^(\w*)(?:\((.*)\))?: (.*)$/`: no `!` between the type and the colon, and no
`breakingHeaderPattern` anywhere in the package. So `feat!: …` does not parse.
The type comes out null and the commit triggers nothing — not a major, not a
minor, nothing.

**It had never worked.** v2.0.0 looked like proof that it did, but that release
came from a merge commit whose branch commit carried a literal `BREAKING CHANGE`
footer in its body, which the analyser matches independently of the header. The
`!` was along for the ride. `CLAUDE.md` promised the marker worked on the
strength of a release that had happened for the other reason.

**Squash merges made it dangerous rather than merely wrong.** The pull request
title is now the only subject semantic-release reads, so a title of `feat!:`
silently releases nothing — and `pr-title.yml` cannot catch it, because the title
is perfectly well formed. The one guard that exists for "a title that releases
nothing" is blind to the one title that looks most like it should release
something.

The fix is the `conventionalcommits` preset, which implements the specification
with `breakingHeaderPattern: /^(\w*)(?:\((.*)\))?!: (.*)$/`. Verified rather than
assumed, on the commit that failed: the same `feat!:` subject that produced "The
commit should not trigger a release" now produces "The release type for the
commit is major", and the next version computes as 3.0.0.

Worth keeping in mind as a shape rather than a fact about one preset: **a default
that silently does nothing is indistinguishable from a default that works, for
exactly as long as something else happens to cover for it.** A literal footer
covered for this one across a dozen releases.

## A failing run keeps what it proved

For most of this tool's life a non-zero exit from the engine banked nothing. The
README gave the reason: reading the engine's JSON log would need a path, the log
can only be configured rather than passed as a flag, and a committed config means
one shared log file that two concurrent runs would both write — "a cache that
reads another run's results is worse than one that forgets".

That was true when it was written and stopped being true the day mule started
generating the engine's configuration itself. The config now lives in the run's
own scratch directory under a random name, so the log path is unique per run and
nothing is shared. The objection had expired and nobody noticed, including while
making the change that expired it.

**What it cost in the meantime, measured on a real project rather than argued.**
A 610-file application with 6 720 mutants had 173 survive. The gate failed, so
nothing was banked — not the 437 files that were clean either — and the next run
started from nothing. The cache could only ever help a project already at the
full score, which is to say a project that did not need it. Three days of work on
this tool and that was the first thing anyone asked of it that it could not do.

`Engine\Outcome` reads the log and answers which files the run vouches for
entirely. **Positive evidence, not absence of blame**: a file must show a mutant
the engine counted towards the score and none it did not. The difference is the
engine that dies halfway — its log names neither the files it never reached nor
any survivor in them, so taking the scope and subtracting the blame would record
verdicts for files nothing had mutated. A test was written for exactly that case
before the code was.

Errors, syntax errors and timeouts count as killed, because the engine counts
them towards the score; refusing them would refuse files the engine was satisfied
with. `escaped`, `uncovered` and `ignored` disqualify, the last because an ignored
mutant was never run whatever the reason.

**The run still fails.** Nothing here touches the exit status. A missing log
vouches for nothing, which is the old behaviour rather than a new failure.

## The engine's version is recorded, because two versions are two gates

Found by spending most of a day failing to explain a hundred and seventy-three
survivors on a project where **every source file, every test file and every
watched file was byte-identical** to what a store of 610 verdicts had been
proven against. Nothing had changed, and the gate answered differently.

Eliminated by measurement, in order: the configuration was equivalent (three
keys, reproduced exactly); `--mutators=` and a config `mutators` map generate
identical counts; the coverage report credited the same test class and the same
ten methods on the line that escaped; the engine's `@default` profile was
unchanged between the two versions, which differ only by two mutators in
`@nullify` that the project does not enable. Each of those was a hypothesis
stated as likely and then measured, and three of them were wrong.

The answer was the engine: a `require: ^0.32` in mule had resolved **0.32.7**
into a project that had deliberately pinned **0.32.0** and enforced the pin with
a composer script — a script dismissed, in this very session, as "checking
nothing" because it tested the global binary rather than the vendor one. The
right fix for that script was to point it at the vendor binary. Deleting it was
not.

**So the version is recorded beside the verdicts and compared before any of them
is reused**, exactly as the declaring files are. A move unproves everything, and
`Summary::engineMoved()` says so in one line naming both versions — because
without that line this failure mode is indistinguishable from a project that
suddenly grew survivors, which is precisely how long it took to find.

**It is a seventh store section rather than another configuration entry.** Every
path in `configuration` is a file that has to exist: `Freshness` re-hashes each
one and refuses a store naming anything missing, and `Store::pruned()` drops
entries whose file has gone. A package name among them would be re-hashed as a
missing file on every read and pruned on every write — the cache would be dead
on arrival. `Snapshot::definition()` is what keeps the complexity of comparing
both in one place.

**And the engine is pinned exactly, not by range.** A patch range that can change
which mutants exist is not a patch range in this context; `composer update` must
not be able to redefine a gate. Recording the version is what makes a change
safe; pinning is what makes it deliberate.

The shape worth remembering, since it has now happened twice in this repository:
**a fact that decides what a verdict means, and that is not a file, is invisible
to a cache built out of file digests.** The configuration files were the first
one. This was the second.
