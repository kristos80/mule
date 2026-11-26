# The keying scheme

What mule stores, what invalidates it, and which guards stand between a cache hit
and a green build on broken code.

`HOWTO.md` is the contract a consumer accepts. This is the mechanism, including
every piece of PHPUnit and Infection behaviour it leans on — all of it verified by
running the pinned versions, none of it inferred.

Pinned: **PHPUnit 13.1.14**, **Infection 0.32.7**, **php-code-coverage 14.5.2**,
**nikic/php-parser 5.9** (already present transitively via Infection),
**PHP ≥ 8.4**.

Measured on two projects throughout: **the application** (613 source files, 404
covered, 51,662 lines, 334 test files / 328 test classes, 6,720 mutants) and **the
library** (41 source files, 36 covered, 28 test files, 902 mutants).

**On counting tests — one capture reports three different totals**, and anything
reconciling artifacts has to expect it. From a single monolithic run of the
application:

| artifact | tests |
|---|---|
| `.cov` `testResults` / `testIds()` | 4,393 |
| `coverage-xml/index.xml` `<test>` | 4,392 |
| `junit.xml` `<testcase>` | 4,426 |

JUnit carries 33 more than coverage does, because a test that executes no
instrumented source still produces a `<testcase>`. Those extras are harmless —
nothing ever names them as covering. The direction that matters is the other one: a
test present in coverage but absent from JUnit breaks the timing lookup (M12), and
is guarded by **G4**. The one-test gap between `.cov` and `index.xml` is
unexplained and has no known consequence; it is recorded here so that a future
reconciliation treating the two as equal is known to be wrong.

---

## 1. Verified mechanics

Load-bearing. If a future change contradicts one of these, re-run the check
before believing the design.

**M1 — Infection consumes a coverage report you hand it.**
`--coverage=DIR --skip-initial-tests` requires exactly:

```
DIR/coverage-xml/index.xml   and per-file  DIR/coverage-xml/**/<name>.php.xml
DIR/junit.xml
```

`IndexXmlCoverageLocator` matches `coverage-xml/index.xml`; `JUnitReportLocator`
matches `(.+\.)?junit\.xml`. The index parser asserts
`/phpunit/project/directory[1]/totals/lines/@executed` is non-empty and non-zero,
then iterates `//file/@href`. `<project source="…">` is the base every `href`
resolves against. For PHPUnit the JUnit report is required.

*Verified at scale:* a report merged from 334 shards drove a full 6,720-mutant
run reporting `Mutation Code Coverage: 100%`.

**M2 — Coverage *attribution* can be captured per test and merged losslessly.**
`--coverage-php` writes "serialization format 4": a plain array with
`buildInformation`, `basePath`, `codeCoverage` (a `ProcessedCodeCoverageData`) and
`testResults`. Merge with `ProcessedCodeCoverageData::merge()`; render with
`SebastianBergmann\CodeCoverage\Report\Facade::fromSerializedData($a)->renderXml()`.
Union semantics are correct — uncovered in one shard, covered in another, comes out
covered.

*Verified on the library:* one monolithic run and a merge of per-test-file shards
produced **identical** mutation verdicts — same count, same killed/escaped split,
same mutator and line for every mutant.

*Verified on the application:* a merge of 334 shards generated exactly the same
**1,174** mutant identities as a monolithic capture over the same 306 files, with
an identical verdict for every mutant that ran.

**This claim covers attribution only, and does not extend to the JUnit report.**
Merging shard JUnits is *not* lossless and silently drops mutants — see M12 and
M13. "Merge the coverage directory" is the wrong mental model; two artifacts with
different merge rules live in it.

**M3 — Line coverage is keyed by test *index*, not by test name.**
`ProcessedCodeCoverageData::lineCoverage()` returns
`file => line => [testIndex => 1]`. The indices resolve through `testIds()`, a
flat list of `Class::method` (or `Class::method#dataset`) strings. The values are
`1`, not hit counts, even when `collectsHitCounts()` is `FALSE`.

**Read the keys, never the values.** Reading values yields a list of `1`s that
looks like valid data and silently produces empty shards. Indices are *shard-local*,
so anything that splits or compares coverage must translate through `testIds()`
first. `merge()` handles the remapping itself.

**M4 — A monolithic capture can be split into per-test-class shards.**
Partition `lineCoverage()` by the indices belonging to each class, re-index them
shard-locally, and carry the matching `testResults` slice. Lines the shard's own
tests did not execute are kept as `[]` rather than dropped, so a split shard is
byte-comparable with a natively captured one.

**`lineCoverage()` holds only lines some test executed** — on the application,
8,629 entries over 610 files with no `NULL` and no `[]` among them. There is no
executable-but-unexecuted skeleton in the data; a line nothing runs is simply
absent. That is what makes thinning safe (§5).

*Verified:* all **328** shards split out of one monolithic capture were
semantically identical to 328 natively captured ones, compared by resolved test-ID
strings per covered line. Split cost: **2.9 s**.

This is what makes the cold path cheap — see §5.

**M5 — `--filter=a.php,b.php` restricts mutation to those files**, and files
outside the filter need no fresh coverage.

**M6 — The JSON log is the verdict record.** Top-level keys: `stats`, `escaped`,
`timeouted`, `killed`, `killedByStaticAnalysis`, `errored`, `syntaxErrors`,
`uncovered`, `ignored`. Note `uncovered`, **not** `notCovered`. Each entry carries
`mutator.{mutatorName, originalFilePath, originalStartLine, originalSourceCode,
mutatedSourceCode}`, `diff` and `processOutput`. **There is no mutant ID in the
log** — the console prints `[ID] <md5>` and `--id` can re-run one, but a
mutant-level cache would have to derive that hash itself. mule keys per file.

**M7 — Attribution in the report decides which tests a mutant faces.** Infection
selects covering tests from `<covered by="Class::method"/>`. Narrowing those
entries narrows what runs.

*Verified:* one mutant (ID `8393c41e…`) was killed by `TrunkTest::testQuad` under
a wide report and by `LeafTest::testTwice` under a report narrowed to the owner
test. **The test files were untouched and carried no covers metadata** — the
narrowing was done by rewriting the report. `#[CoversClass]` has the same effect
and makes mule's narrowing unnecessary, but is never required of a consumer.

**M8 — A coverage report's `hash` attribute is recomputed from disk at render
time.** It equals `sha1_file()` of the current file, *not* a digest of the data
the report describes. A report rendered from stale cached data therefore carries a
hash that matches the file and looks internally consistent. **Unusable as a
staleness guard.** mule keys from digests it took itself, at capture time.

**M9 — `basePath` is derived from the set of covered files**, and lands in
`<project source>`. Merging a subset of shards can produce a *deeper* base path
than the source root, silently relocating every `href`. mule sets it explicitly
and never derives it.

**M10 — Coverage attribution is deterministic.** Two full runs under
`executionOrder="random"` produced byte-identical per-line covering-test sets
across all files of the library.

**M11 — Infection resolves paths relative to its config file's directory, not
the working directory.** A configuration placed in `/tmp` makes Infection look
for `phpunit.xml` in `/tmp` and fail. mule must therefore render its
configuration inside the project, which is also what lets it digest it (§4).
Note also that `-c` is parsed as a command name; only `--configuration=` works.

**M12 — Infection silently skips mutants whose covering tests look slow, and the
JSON log does not record them.**
`Process\Runner\MutationTestingRunner::takingTooLong()` compares
`Mutation::getNominalTestExecutionTime()` — the summed time of the mutant's
covering tests, read from **junit.xml** — against the configured timeout. If it
reaches the timeout the mutant becomes `MutantExecutionResult::createFromTimeSkippedMutant()`,
i.e. `DetectionStatus::SKIPPED`.

**A skipped mutant appears in no detail array of the JSON log.** There is no
`skipped` key beside `killed`/`escaped`/`uncovered`; the only trace is
`stats.skippedCount`. It is not counted as escaped, so MSI does not fall. A
mutant that was never executed is therefore indistinguishable, in the log's
detail, from one that never existed.

**M13 — Merging shard JUnit reports inflates those times, which triggers M12.**
Each shard is a separate cold process, so every test carries its own bootstrap
cost. Measured over the application's 4,426 test cases — the same 4,426 in both
reports, none missing:

| | total | median per test | p90 | max |
|---|---|---|---|---|
| monolithic junit | 24.29 s | — | — | — |
| merged from 334 shards | **70.34 s** | **×1.89** | ×3.99 | ×72.5 |

Consequences, measured: on a 306-file subset, both coverage sources generated
exactly the same **1,174** mutants with **identical verdicts for every mutant that
ran** — but merged coverage **skipped 40** of them (1,119 killed → 1,079), MSI
95.32 → 95.15. On the warm run of §9, 10 of 165 mutants were skipped.

**The fix is to keep timings and attribution as separate artifacts.** The
`<covered by>` attribution comes from merged shards; the test *times* come from
the most recent monolithic reference capture. Verified: substituting the
monolithic `junit.xml` into the merged report took the warm run from 149 killed
/ 10 skipped to **159 killed / 0 skipped**, same 165 mutants.

**And the artifact is per test *class*, not per test.**
`JUnitTestExecutionInfoAdder::createCompleteTestLocation()` reduces a covering
test to `explode(':', $method, 2)[0]` and looks *that* up;
`TestTotalTimeCalculator::uniqueTestLocations()` then keeps one time per distinct
class. So:

```
nominal = Σ over distinct test CLASSES covering the mutant, of that class's time
```

A mutant covered by forty methods of one class costs that class's total once. A
hub file covered by sixty-five classes sums sixty-five totals — which is why
inflation bites hardest exactly where the cache is least use, and why the 40
skipped mutants clustered in `Domain/` value objects.

`JUnitTestFileDataProvider::getTestFileInfo()` reads exactly two attributes off
`//testsuite[@name="…"][1]` — **`file` and `time`** — and throws `TestNotFound`
for a class it cannot find. That is G4, with a real exception behind it.

**So mule synthesises the report rather than caching PHPUnit's.** One
`<testsuite name file time>` node per class is all Infection reads. Verified on
the application: a **71 KB** synthesised report drove the same warm run as the
original **1,881 KB** one — 165 mutants, 159 killed, **0 skipped**, identical.

**M14 — MSI counts timeouts, errors and syntax errors as kills, and drops skipped
and ignored mutants from the denominator.** From `Metrics\Calculator` and
`MetricsCalculator::getTestedMutantsCount()`:

```
MSI = 100 × (killed + killedByStaticAnalysis + errored + syntaxErrors
             + timedOut   unless --with-timeouts)
          ÷ (total − skipped − ignored)
```

Three consequences that matter more than the formula:

1. **A skipped mutant leaves both numerator and denominator.** That is why 40
   skips out of 1,174 moved MSI by 0.17 points (M13) instead of by 3.4.
2. **A timed-out mutant scores as detected by default.** `timeoutsAsEscaped` is
   `FALSE` unless `--with-timeouts` is passed. So a mutant nobody verified — the
   tests merely took too long — is counted as proof.
3. **A syntax-error mutant also scores as detected.** That is a broken mutator,
   not a detection, and must never be banked as one.

**"Detected by Infection" and "proven to mule" are therefore not the same
predicate.** mule banks only `killed`; see **G8**.

**M15 — `killedByStaticAnalysis` means the tests *failed* to detect the mutant.**
Not a second, equal kind of proof — the opposite. `MutantProcessContainer::hasNext()`
is documented as *"Container has a next process only if Mutant is Escaped"*, and
the lazily-created processes are the static-analysis factories
(`StaticAnalysis\PHPStan\Process\PHPStanMutantProcessFactory`,
`…\Mago\…`), while `process[0]` is the test framework. So the chain is:

```
run the tests against the mutant
    └─ escaped?  and a static analysis tool is configured
           └─ run PHPStan/Mago
                  └─ non-zero exit → KILLED_BY_STATIC_ANALYSIS
```

A mutant in that bucket escaped the test suite and was then flagged by a type
checker. By M14 it lands in the MSI **numerator**, so a documented test gap raises
the score. For a tool whose purpose is proving that tests leave no edge case
unpinned, it is the single most misleading status Infection reports, and it is
**not bankable** (G8).

It is zero on both measured projects because neither configures a static analysis
tool, so accepting it would have been a latent hole rather than a present bug —
which is exactly the kind that ships.

**M16 — A test class that executes no instrumented source is absent from
`testIds()`, so splitting produces no shard for it.** This is the 33-test gap in
the counts table above, materialising: on the application, splitting 4,393 test
ids yielded 328 shards while 331+ test classes exist. The missing ones are **pure
enum tests** — `enum Locale`, `enum ResyncLineVerdict`, `enum ResyncRefusalReason`
— whose cases generate no executable lines. Captured in isolation each one reports
`testIds=1, coveredLines=0`; captured with the suite, it is not in `testIds` at
all.

Harmless for attribution, since a test attributing nothing has nothing to merge.
**Dangerous if inferred rather than recorded**, because "this test covers nothing"
and "this test's shard went missing" are then the same observation. So the shard
set is enumerated from the **test files on disk** (G9) and a test file with no
shard is stored in an explicit *covers-nothing* state, exactly as source files
carry one of four states (§4).

It is also why `doctor` cannot measure footprints from coverage alone: a test that
executes no source is invisible in every coverage artifact mule reads.

---

## 2. Where dependency edges come from

**By default, from the coverage report.** For each source file, the report names
its covering tests; for each test, the report names every source file it executed.
That is the *dynamic* closure, and it is strictly more accurate than any static
analysis: it records what actually ran.

Measured footprints:

| | files / test classes | files one test executes | …as a share of the project | tests covering one file (median) | closure per file | invalidated per edit |
|---|---|---|---|---|---|---|
| the application | 404 / 327 | ~5 (median 3, max 38) | **1.3 %** | **1** | median 11 | **11 / 404 — 2.7 %** |
| the library | 29 / 28 | ~5 (median 3, max 27) | **17.4 %** | **4** (14 % of the suite) | **29 of 29** | 29 / 36 — **81 %** |

**The driver is project scale, not test-suite shape.** Both projects follow the
same conventions, mock only their I/O at the same five-port seam, declare no covers
metadata, and have a near-identical *absolute* footprint — about five source files
per test class. What differs is what five files means: 17 % of a 29-file project
against 1.3 % of a 404-file one. At that density a handful of overlapping tests
saturates the library, and its median file is covered by four of its twenty-eight
tests rather than by one.

An earlier draft of this section blamed the library's architecture — "its unit
suite drives the whole object graph through I/O fakes". **That was wrong and the
measurement above refutes it.** Per-test footprints are the same in both. A small
project is dense by arithmetic, and no amount of mocking discipline changes it.

The consequence is friendlier than it first looks: **the need for caching and the
benefit from it scale together.** The library cannot be cached and does not need to
be — its entire gate is 51 s. The application needs it and gets 437 s → 22 s. There
is no measured project that both needs caching and cannot have it.

**Optionally, from a static reference graph** — only when `"narrow": true`. Once
mule narrows the report (§6), the report can no longer describe dependencies, so
the edges must come from somewhere else. That was the one objection that made this
whole design look impossible, and the graph is the answer to it.

Built with `PhpParser\NodeVisitor\NameResolver`. Nodes are project files; each
file's declared class/interface/trait/enum is mapped to its path so edges resolve
without the autoloader. Edges are every resolved `PhpParser\Node\Name` landing on
a project file — which deliberately includes `extends`, `implements`,
`use TraitX`, attribute class names, static calls, class-constant access,
`instanceof`, `catch` types and parameter/return types, all of which are `Name`
nodes after resolution.

**Not `use` statements.** On the library an import-only graph found 211 of 246
edges and **missed 35 — 14 %** — every one a same-namespace reference, which needs
no import:

```
src/Cache/Freshness.php  ->  src/Cache/Universe.php
src/Cache/Keying.php     ->  src/Cache/Reading.php
```

The namespace with the densest internal references is exactly the one an
import-only graph is blindest to.

### Soundness of the static graph

It must *over*-approximate execution. Over-approximating costs a needless cache
miss; under-approximating holds a verdict proven against code mule never knew was
involved. Over-approximation holds while every class reference is statically
resolvable — `HOWTO.md` **N3** ("collaborators are named, not computed").

Scanned for: `new $expr`, `$expr::method()`, `$expr::CONST`,
`call_user_func`/`call_user_func_array`, `class_exists`, `is_callable`,
`constant`, `compact`, `extract`, reflection-driven construction. `$object::class`
is whitelisted — reading an instance's class name creates no dependency. (On the
library this scan found exactly one hit, the whitelisted case.)

### What neither source can see

Non-class inputs: fixtures a test reads, `require`d files, files declaring only
global functions or constants. mule digests every file under `source`, `tests` and
`support` whether or not anything gave it an edge, and treats any file it cannot
place as *watched* — a change to it invalidates everything. Conservative on
purpose. Anything outside those roots is undetectable, which is what `HOWTO.md`
**P2** ("tests are deterministic") is for.

---

## 3. Signature

```
sig(F) = sha256(raw bytes of F)
```

Raw bytes, no normalisation, in v1.

**Why not stripped comments.** The appeal is real — a comment-only edit would stop
invalidating anything, and on the library that one case alone costs 29 of 36
verdicts. The objections:

1. `@infection-ignore-all` and `@codeCoverageIgnore` live in doc comments and
   change behaviour. Strip them and two semantically different files hash
   identically — a wrong cache hit, the one failure mode with no recovery.
2. Cached verdicts carry `originalStartLine`. A comment-only edit shifts lines, so
   a cache hit reports findings against stale line numbers.

**Under `content` the stale-line problem cannot occur, by definition.** A cache hit
means the bytes are identical, so a cached `originalStartLine` still points where
it did. There is nothing to re-verify at render time, and no verification step is
specified — adding one would be guarding against a state the key makes
unreachable. The problem exists only under `code`, which is precisely the reason
`code` is deferred rather than merely discouraged.

A project that bans suppression annotations outright could strip safely. So
`"signature": "code"` is reserved; if it is ever built, mule **must** first verify
that no suppression annotation exists anywhere under `source` and refuse the mode
otherwise, and **must** re-resolve line numbers before rendering a cached finding.
**v1 accepts only `content` and rejects anything else** (§11).

---

## 4. The key

For a mutable source file `F`:

```
key(F) = sha256(
      "F" => sig(F)
    + for G in sort(deps(F)):  "G" => sig(G)
    + for T in sort(tests(F)): "T" => sig(T)
    + toolchain()
)
```

`tests(F)` is the set of test files whose tests cover `F` — **not** every test in
the project. A test that does not cover `F` is absent from `F`'s key, so editing
an unrelated test cannot invalidate `F`.

`deps(F)` is every source file those covering tests execute — from the coverage
report by default, or `closure(F) ∪ closure(owner(F))` from the static graph when
narrowing.

**`deps(F)` is deliberately wider than "files `F` itself references", and
narrowing it would be unsound.** If `GateTest` covers both `Gate.php` and
`Decoded.php`, then `Decoded.php`'s mutants are run against `GateTest`, whose
behaviour depends on `Gate.php` — so a change to `Gate.php` can flip a
`Decoded.php` verdict even though `Decoded.php` never mentions `Gate`. The
dependency runs through the shared *test*, not through the source.

This is not a conservatism knob. It is the whole reason the library measures 81 %
invalidation and the application 2.7 % (§2): the same rule, applied to two
different test-suite shapes. Any proposal to tighten the key should be checked
against that pair of numbers first, because a key that leaves out shared-test
edges buys its granularity by banking verdicts it cannot justify.

### The key is derived, never the only record

`key(F)` is a comparison value. The store keeps the **structured** edge maps it is
computed from — per-test digest and touch list, per-source digest, the reference
capture it was measured against — and recomputes the key from them.

Collapsing them into one hash would still be *correct* and would lose everything
else: `doctor` could not report a distribution, a failure could not name which
input moved, and an invalidated file could not be distinguished from a file whose
test merely got re-captured. Correctness without granularity is the failure mode
that looks like success.

```
toolchain() = sha256(
      sig(composer.lock)
    + sig(the project's PHPUnit configuration)
    + sig(the Infection configuration mule renders)
    + the resolved mutator set
    + PHP_VERSION
    + coverage driver name and version
    + mule's own version
)
```

A toolchain change invalidates the whole store at once; that is correct, not an
error.

### The two tool configurations are handled oppositely, and must be

**PHPUnit's is the project's, used as given, and digested.** mule cannot invent a
bootstrap, a testsuite list or a `<source>` include — only the project knows
those, and `--coverage-php` produces nothing useful without them. mule overrides
on the command line what it needs per shard (the test file, `--coverage-php`,
`--log-junit`) and touches the file itself not at all. It goes in the key because
`<source><exclude>` decides what is instrumented, which decides coverage, which
decides every verdict.

**Infection's is rendered by mule, and a project-level one is refused.** Not
ignored — *refused*, with a message naming the file. Every key that would defeat
a guard is settable there, and all ten of these exist in Infection 0.32.7's own
schema:

| key | what it would defeat |
|---|---|
| `ignoreMsiWithNoMutations` | **G5** — a zero-mutant run exits 0 |
| `staticAnalysisTool` | **M15, G8** — test gaps become apparent successes |
| `timeoutsAsEscaped` | **G8** — a timeout scores as a kill again |
| `timeout` | **M12** — decides which mutants are silently skipped |
| `minMsi` / `minCoveredMsi` | **G5** — the floor the gate rests on |
| `logs` | **M6** — mule parses the JSON log it asked for |
| `tmpDir` | §4 — mule owns the cache location |
| `mutators` / `source` | §4 — both are in the cache key |

**Silently ignoring the file is the worse of the two wrong answers.** A consumer
who writes `ignoreMsiWithNoMutations: true` and sees a green build has been told
something false by their own configuration. Honouring it defeats the guard;
ignoring it defeats the person. So mule fails and says where to put what it
found: `mule.json` carries `mutators`, `exclude`, `min-coverage` and `threads` in
mule's own vocabulary, and names no PHPUnit or Infection key at all.

### Where things live, and who ignores them

**mule writes the `.gitignore` for its own machine-local artifacts rather than
asking a consumer to.** Measured reason: the shard cache for the application is
**61 MB** across 334 shards (§5), and the store beside it is committed on
purpose. A committed store and 61 MB of scratch cannot share a directory — one
careless `git add .mule` and the repository is 61 MB heavier.

```
.mule/verified.json     committed: the store, below
.mule/cache/            machine-local: shards, merged reports, timings, and
.mule/cache/.gitignore  the engine's tmpDir — plus a "*" that ignores all of
                        it, written by mule on first run
```

Verified: with that one-line file in place, `git add .mule` stages the store and
nothing else, and the cache needs no entry in the consumer's own `.gitignore`.

**Two of the three scratch directories are mule's to place, and both belong
inside the cache.** mule renders the Infection configuration itself (§4, M11) so
it chooses `tmpDir`, and it generates the coverage reports so it chooses their
path. Neither belongs at the project root, where a consumer has to know about it
to ignore it.

**The third is not mule's.** `cacheDirectory` in the consumer's `phpunit.xml` is
theirs, and wants ignoring for hygiene rather than for correctness:
php-code-coverage's own cache is content-keyed, and PHPUnit's result cache only
affects ordering, which M10 showed does not move attribution. `doctor` may report
that it is tracked; mule must not move it.

### Store

```
.mule/verified.json
```

- `verdicts`: `path => { key, mutants, killed, escaped, timeouts, errors }`
- `coverage`: `path => { executable, executed, unreached[] }`
- `tests`: `path => { sig, touches[] }` — what each test file was seen to execute
- `timings`: `{ key, capturedAt, tests: path => seconds }` — the reference capture
  (§5), keyed separately so a changed test body marks it stale

Shards are **JSON, one file per test class**, under `.mule/cache/`. Not a
`require`d PHP file: a cache entry that is executed is a cache entry that can run
code, and these live in a working tree beside the sources they describe. Decoding
all 328 of the application's costs 0.07 s, so the safer format is also free. on its own
- `toolchain`: the digest above

**Every mutable source file is in exactly one of four states, and the state is
recorded rather than implied by absence.** A file missing from the store is
indistinguishable from a file nobody thought about, which is how a mutable file
silently stops being gated:

| state | meaning | banked? |
|---|---|---|
| `cacheable` | has a verdict under a key that still holds | yes |
| `excluded` | named in `mule.json` `exclude`; not mutated, not required to be covered | n/a |
| `unowned` | mutable, narrowing on, no owner test found (GN3) | never |
| `refused` | mutable, excluded from caching by a GN2 construct, with the construct and line | never, re-run every time |

`doctor` and every run print the counts for all four. A file that moves from
`cacheable` to `unowned` or `refused` is a regression in gating even when the gate
still passes, so the counts belong in the output, not only in the store.

Written beside the old file and moved over, so a reader sees one complete store or
the other, under a lock for the merge.

**Bank only after the engine finishes, and only for files whose key still holds.**
A file edited while the engine ran has a verdict belonging to neither version;
re-digesting at bank time means the next run re-proves it. The hole this closes is
the edit later reverted — the bytes return, the store vouches for them, and the
proof was against something else.

---

## 5. Coverage: capture, cache, merge

On the library this stage is worth 3 s and is nearly pointless. On the application
it turns a 30.8 s capture into a 1 s one — **worth ~30 s of every warm run**, which
is more than the mutation stage costs there (§9). Build it.

| | application | library |
|---|---|---|
| suite, no coverage | 8.2 s | — |
| monolithic capture (`--coverage-php`) | **30.8 s** | 3.7 s |
| split into per-test shards (M4) | **2.9 s** | — |
| native per-test capture, 10-way parallel | 61 s | 3 s |
| one shard re-captured | ~1.0 s | ~0.5 s |
| merge all shards → report | **3.7 s** | 1.0 s |

Merge breaks down as 0.76 s loading and merging `.cov`, 2.80 s rendering XML,
0.11 s on JUnit, for 334 shards / 4,393 tests / 610 files. Measured at 3.7 s in
isolation and 5 s inside the warm run of §9; the difference is second-resolution
timing and a warm page cache, not a second code path.

**Cold path: capture once, monolithically, then split (M4).** Native per-file
capture costs 61 s against 34 s for capture-plus-split — twice the price for the
same shards. Never shard the cold path directly.

**Warm path: re-capture only the dirty test files, then merge.**

**A shard is 99 % entries that name no test, and none of them need storing.**
Measured on one application shard: 610 files and 8,629 line entries, of which
**88 — 1.02 % — actually carry a test**. The rest are lines some *other* test
executed, which the split records as `[]` so the shard mirrors the capture it
came from.

**Persist only the entries that name a test.** The ones that name none are
recoverable, because a complete shard set contains every line any test executed —
so their union is the capture. Measured end to end on the application, through the
real cache format:

| | |
|---|---|
| 328 shards written as JSON | **0.35 s, 2.1 MB total** (mean 6 KB) |
| read back and rehydrated | 0.07 s |
| merged | 0.09 s |
| against the fat `.cov` equivalent | 61 MB — **29× smaller** |

and the round trip is exact: 404 files, 8,629 entries, 4,393 test results, all
identical to the monolithic capture. Half a second to rebuild the whole report
from cache is what makes the warm path worth having.

An earlier draft of this section proposed storing the skeleton once as a separate
artifact. That is unnecessary: there is no skeleton to store. The only case where
a line would go missing is one no test executes at all, and such a line is absent
from the monolithic capture too — so the two agree.

Note this does **not** make `Split`'s `[]` entries pointless. Split is faithful
to the capture on purpose, which is what lets a split shard be compared against a
natively captured one (M4). Thinning is a property of *persistence*, applied on
the way to disk.

**Each shard records** its test file's digest *and the digest of every source file
it recorded non-null line data for, at capture time.*

**Reuse rule.** Reuse a shard only when its test file digest **and every one of
those recorded source digests** still match.

Stricter than "its test file did not change", and the difference is a correctness
bug. A shard holds line data for files other than its own; reusing it after one of
those changed puts coverage for old line numbers into the merged report. Measured
consequence on one stale file:

```xml
<lines total="13" comments="2" code="11" executable="2" executed="2" percent="100.00"/>
<method name="div" signature="div(int $a, int $b): int" executable="0" executed="0" coverage="100"/>
```

`percent="100.00"` for a file whose brand-new `div()` had never been executed,
coverage recorded against lines now blank, and a `hash` matching disk (M8).
Infection then generated **zero** mutants for that file, dropped it from the
report, and exited 0.

**Merge** per M2. Set `basePath` to the configured source root explicitly (M9).

**Do not merge shard JUnit reports into the report Infection reads.** Appending
every `<testsuite>` child into one `<testsuites>` root produces a structurally
valid file with correct test names and inflated times, which silently skips
mutants (M12, M13). Instead:

- **attribution** — from the merged coverage shards, as above;
- **timings** — from the most recent monolithic reference capture, cached as its
  own artifact and refreshed only when the cold path runs.

A test present in the attribution but missing from the timing artifact (a test
added since the last reference capture) must force a fresh reference capture
rather than be defaulted to zero, because zero is the one value that can never
trigger M12 and so hides the problem instead of surfacing it.

### Timing freshness, which is not the same as completeness

**G4** asserts every attributed test has a timing. It does not assert the timing is
current: a test whose body changes while its name stays the same keeps its old
number.

**The nominal time has two effects, and they pull in opposite directions.** From
`MutationTestingRunner::takingTooLong()` and
`Process\Factory\MutantProcessContainerFactory`:

```
skip the mutant         if  nominal >= configuredTimeout          (default 10 s)
per-mutant timeout    = min(5 + 5 × nominal, configuredTimeout)   (both consts = 5)
```

So an **inflated** nominal skips the mutant, while a **deflated** one tightens the
process timeout around it. Each has a different failure mode:

**Stale-high / inflated** — the test got faster, or the number came from a
cold-process capture (M13). `nominal` crosses the configured timeout and the mutant
is **skipped**. **G2** fails the run. Recovery: discard the timing artifact, take a
fresh reference capture, retry once; a second failure is a real defect and must not
be retried again.

**Stale-low / deflated** — the test got slower. The mutant is *not* skipped, but
its process timeout is computed from the stale number, so a run that now takes
longer than `5 + 5 × stale` is killed by the timeout and lands in `timedOut`. By
M14 that **scores as a kill**, converting "never verified" into "proven".

**Therefore `--with-timeouts` is mandatory, not a preference** (G8). With it a
timeout is scored as escaped, lowers MSI and fails the gate.

**How much deflation actually matters, for the suites mule targets.** The
`5 + 5 × nominal` formula has a 5-second floor, so for a 2 ms unit test the
per-mutant timeout is ~5.01 s either way — the floor absorbs the drift entirely,
and a test must get *seconds* slower before deflation bites. Inflation is the
asymmetric risk, because `nominal` is a **sum over every covering test**: a hub
value object with 200 covering tests reaches the 10 s threshold on per-test
inflation of 50 ms, which is exactly the M13 regime. A test drifting slower but
staying well under the timeout is a correctness non-issue and costs only wall
clock.

**A timeout is an inconclusive verdict, and mule must not guess why.** An earlier
draft of this section called a non-zero `timeOutCount` a diagnostic about the
timing artifact. That overclaims. A stale-low nominal is one cause; the others are
properties of the mutant itself — a mutated loop condition or a removed increment
produces a genuine infinite loop, and a mutated boundary can make an operation
pathologically slower. Those are mutants the tests might well have killed given
the chance, and nobody gave them one.

So mule reports the **measured fact** — mutant, file, line, the limit it hit, and
the nominal time that limit was derived from — and keeps the suspected cause
separate from it. Never bankable either way (G8): a timeout is an absence of
evidence, not evidence of absence.

**The two causes can be told apart mechanically**, and mule should do that rather
than report ambiguity. Re-run the affected file alone with a generously raised
`timeout`:

- now killed or escaped → the limit was the problem. Refresh the timing artifact
  and carry on.
- times out again under a limit many times the first → the mutant is pathological.
  Report it against the file as a mutant that cannot be evaluated, and leave the
  file unbanked until a human decides.

Infection's `--id` would narrow this to the single mutant rather than the whole
file, but the mutant ID is absent from the JSON log (M6) and recoverable only from
the console or a tmp-config filename, so re-running the file is the version with no
string-parsing in it.

The timing artifact carries its own key — the digest of every test file plus
`toolchain()` — so a changed test body marks it stale and the next cold path
refreshes it. That turns freshness from a guard into a cache key, which is the right
shape for it; G2 and G8 remain the backstop for the window in between.

---

## 6. Narrowing — deferred, not in v1

Specified here because the measurements that motivate it are worth keeping, and
because the one objection that made this whole design look impossible dies here
(§2). **It is not built**: v1 rejects `"narrow": true` with an error (§11), and
everything in this section has only been exercised on a four-class fixture.

Two reports from one capture:

- **wide** — every covering test. This is the coverage percentage the consumer is
  shown, with unchanged meaning, and the basis of `min-coverage`.
- **narrow** — only each file's owner test. Handed to Infection, and nothing else.

Narrow is built by dropping every `<covered by>` whose class is not the file's
owner. Narrowing is safe in exactly one direction: fewer tests can only let a
mutant **survive**, never falsely kill it. The unsafe direction is a mutant with
*no* test left — a line orphaned by narrowing has nothing to attach to, so
Infection silently generates fewer mutants. That is guard **GN1**, and it is why
narrowing without `HOWTO.md` **N2** ("the owner test reaches all of its own
file") turns a failing gate green: measured, 10
mutants → 3, MSI 50 % → 100 %, exit 1 → exit 0, with
`Mutation Code Coverage: 100%` printed in both cases.

---

## 7. Guards

Hard failures. None is a warning. **G1–G9 always apply**; **GN1–GN3 apply only
when narrowing is on** — a separate namespace so the core list can grow without
renumbering, which it has already done three times. G2, G4 and G8 exist because the defects measured in this
document each slipped past an earlier draft of this list — which is the argument
for the differential fixture below rather than for a longer list.

**G1 — Zero mutants.** For every file mule asked the engine to re-prove, the JSON
log must contain at least one mutant. A file producing none, and not known to be
mutant-free, fails. Infection's aggregate cannot catch this: MSI averages over
mutants that were generated, so a file producing none does not lower it —
measured, a stale file sat beside a healthy one and the run reported
`Mutation Code Coverage: 100%`, MSI 75 %, exit 0, with the stale file absent from
the report entirely.

**G2 — No skipped mutants.** `stats.skippedCount` must be `0`. A skipped mutant
was generated, was counted in `totalMutantsCount`, appears in **no** detail array
of the JSON log, and does not lower MSI (M12). It is an unverified mutant wearing
the costume of a verified one.

**G2 is not implied by G1.** A file can produce its full complement of mutants and
still have some of them never execute — G1 counts mutants, G2 counts executions.
This is the failure mode that got past every other guard on this list: in the
measured differential, 40 of 1,174 mutants were skipped while the aggregate moved
from MSI 95.32 to 95.15, a change small enough to read as noise.

**G3 — Line validity.** Every line marked covered in the merged report must be an
executable line of the file as it is on disk. Catches a stale shard that G1 would
miss because the file still produced mutants.

**G4 — Timing completeness.** Every test named as covering in the attribution must
be present in the timing artifact, and the timing artifact must come from a single
capture. A missing entry forces a fresh reference capture; it must never default
to zero (§5, M12).

**G5 — Gate flags.** Always pass `--min-msi` and `--min-covered-msi`. **Never**
pass `--ignore-msi-with-no-mutations`: measured, a zero-mutant run exits 1 with
`--min-msi=100` and exits **0** once that flag is added. It must never appear in a
command mule builds.

**G6 — Shard failure.** A test file failing during capture fails the run, with
PHPUnit's output.

**G7 — Machine-local config.** An absolute path in `mule.json` fails.

**G8 — Proven means killed by a test.** A file is banked only if every one of its
mutants is `killed` — killed by the test framework, nothing else. By M14 and M15, Infection
widens "detected" in four ways (timeouts, errors, syntax errors, static-analysis
kills) and shrinks its denominator in two more (skipped, ignored), so five
assertions follow:

- **`stats.killedByStaticAnalysisCount` must be `0`**, and is reported as a *test
  gap*, naming the mutants. By M15 that status means the suite escaped the mutant
  and a type checker caught it — the precise thing mule exists to surface, scored
  by Infection as a success. v1 never passes `--static-analysis-tool` (§11).

- **`--with-timeouts` is always passed**, so a timed-out mutant is scored as
  escaped rather than as a kill. Mandatory — see "Timing freshness" in §5.
- **`stats.syntaxErrorCount` must be `0`.** A mutant that will not parse is a
  broken mutator, not a detection.
- **`stats.ignoredCount` must be `0` for any file mule banks.** An ignored mutant
  leaves the denominator exactly as a skipped one does, so a file full of
  `@infection-ignore-all` reaches MSI 100 % without being proven. Consumers who
  use suppressions get a report, not a verdict.
- **`stats.timeOutCount` must be `0`**, and when it is not, the run is reported as
  a fault in the *timing artifact* rather than as a finding against the file (§5).
  A timeout means the process was cut off before the test could decide, so it is
  an absence of evidence either way.

**G9 — Discovery enumerates the tree, not the store.** Every run walks `source`,
`tests` and `support` on disk and reconciles that against the store. A file present
on disk and absent from the store is a **hard failure** until it is classified into
one of the four states (§4).

**A store-driven loop can only ever rediscover what it already knows**, which is
how a file silently stops being gated. Two cases make it concrete, and neither is
reachable by iterating shards:

- **A new test file** has no shard at all. Undiscovered, its coverage never enters
  the merged report, so source it covers keeps looking unreached and files it newly
  covers keep a verdict keyed on a test set that no longer matches. That direction
  produces a false *failure* rather than a false pass — adding a test cannot
  un-kill a mutant — but it makes the gate lie about which files are provable, and
  it is the kind of wrongness that gets worked around rather than fixed.
- **A new source file** that nothing covers must be gated, not ignored. Its mutants
  land in `uncovered`, which does lower MSI (M14), so the gate catches it — but only
  if the file was enumerated in the first place.

The same rule that forces a fresh reference capture for a newly attributed test
(G4) therefore applies to the coverage universe itself: the filesystem is the
authority on what exists, and the store is only the authority on what has been
proven.

**GN1 — Orphan lines.** *Narrowing only.* Any line covered in the wide report and
orphaned in the narrow one fails the run, naming file, line and the foreign test
that reaches it. See §6.

**GN2 — Dynamic resolution.** *Narrowing only.* A file containing a construct from
§2 is refused for caching along with everything whose closure reaches it, reported
with the construct and its line. The run proceeds, uncached.

**GN3 — Unowned files.** *Narrowing only.* A mutable source file with no owner
test is never banked and is listed every run.

### The differential fixture

Not a guard but the test that establishes the guards work — and the only thing on
this page that would have caught M13 before it shipped.

For a fixed project state, a run driven by a monolithic capture and a run driven by
the cached-shard pipeline must produce the **same set of mutant identities** —
`(mutator, file, line, hash(mutatedSourceCode))` — and the **same verdict for
each**. Comparing aggregate counts is not sufficient: in the measured differential
both runs reported exactly 1,174 mutants and the defect was visible only at
identity level, in the killed/skipped split.

It belongs in mule's own integration suite, parameterised over at least one project
containing hub files, because hub files are where M13 bites.

### The fixture's project, and what else it must cover

The differential alone only proves the pipeline reproduces verdicts on a *clean*
run. The store is a state machine, and its interesting states are all the dirty
ones. The fixture project therefore needs, deliberately constructed:

- a **hub file** several tests execute, so M13's inflation regime is reachable;
- a **slow test** whose covering set can be pushed over the 10 s threshold, so the
  skip path (M12, G2) is exercised rather than assumed;
- a file whose only coverage comes from **another class's test**, for the narrowing
  path if it is ever built (GN1);
- a file with **no mutable code**, to distinguish "legitimately zero mutants" from
  G1's failure.

And the scenarios, none of which the clean differential reaches:

| scenario | required outcome |
|---|---|
| source edited *while* the engine runs | not banked; next run re-proves it |
| source edited, then reverted to the proven bytes | not banked off the stale proof (§4) |
| crash between writing the store and the swap | reader sees one complete store, never half |
| two runs finishing simultaneously | neither loses the other's entries |
| toolchain digest changes | whole store invalidated exactly once |
| shard reused after a file it covered changed | refused, not silently merged (§5) |
| test added since the last reference capture | forces a fresh capture, never a zero timing (G4) |
| **new test file**, covering already-covered source | discovered from disk; affected keys move (G9) |
| **new test file**, covering previously uncovered source | discovered; that source becomes provable, not still-unreached (G9) |
| **new source file**, nothing covers it | discovered and gated, never silently absent (G9) |
| mutant that genuinely never terminates | reported as inconclusive, not banked, cause distinguished from a stale timing (§5) |

The revert case is the one most easily missed and the hardest to recover from: the
bytes come back, the store still vouches for them, and the proof was against
something else entirely.

---

## 8. Tier detection

`mule doctor` writes no store and mutates nothing. One capture, then:

```
invalidates(X) = | { F : X ∈ deps(F) } |
```

weighted by each file's real mutant count to turn files into seconds.

- **Tier A** — median invalidation low enough that the dynamic closure suffices.
  The application: median 11 of 404, 2.7 %.
- **Tier B** — Tier A with hot spots. Names them. On the application:
  `AbstractController.php` 215 (53 %), `WebsiteCode.php` 200 (50 %),
  `Website.php` 181 (45 %), `OrderReference.php` 174 (43 %) — a shared base class
  and three value objects nearly everything constructs. The width is real, not an
  artefact.
- **Tier C** — nothing cacheable as it stands. Reports the number and the widest
  test footprints rather than installing anyway.

**Tier C is usually benign, and `doctor` must say which kind it is.** The library
is Tier C: a comment-only change to one source file marks 29 of its 36 files
stale, and its invalidation rule (`moved ∪ everything touched by any test that
runs a moved file`) is *exactly* the sound one. But the cause is scale (§2), not a
badly shaped suite — and a 29-file project has a 51-second gate, so it has nothing
to gain from a cache in the first place.

So `doctor` must report the *reason* alongside the tier, because the two kinds call
for opposite responses:

- **Small project.** Dense by arithmetic. Expected saving ~0 %, full gate already
  fast. The correct advice is "you do not need this tool", not "restructure your
  tests".
- **Large project with wide footprints.** A suite where single tests execute a
  large share of a big project. Here restructuring or narrowing (§6) would pay, and
  `doctor` should name the widest test footprints.

Two earlier attempts produced a correct cache that saved nothing, on a project of
the first kind. The failure was never the architecture — it was measuring the wrong
project, and then explaining the result with the wrong cause.

---

## 9. Results

Every figure below is an **observation**. Projections are in §10 and labelled.

**The application** — 613 source files, 404 covered, 6,720 mutants, 8 threads:

| | mutants | wall clock |
|---|---|---|
| coverage capture, monolithic | — | 30.8 s |
| split into 328 shards (M4) | — | 2.9 s |
| merge all shards → report | — | 3.7 s |
| mutant generation alone (`--dry-run`) | 6,720 | 115 s |
| **full gate** | 6,720 | **406 s** (6 m 45 s) |
| one source file, filtered | 9–30 | 1–5 s |
| **warm run, median edit** | 165 | **22 s** |

The warm run was measured end to end, not projected. The median-impact file was
chosen from the application's own coverage report
(`Controller/Api/Operator/OrderResyncController.php`, invalidation 11 — the
median), and the work was then carried out:

```
1. re-capture the 1 dirty shard        1 s
2. merge 334 shards into a report      5 s
3. re-mutate the 11 invalidated files  16 s   (165 mutants)
   -------------------------------------------
   warm run                            22 s
```

against a cold path of 30.8 s capture + 406 s mutation ≈ **437 s**, so **≈ 20×**.

**Note the asymmetry that measurement exposed, and that the old implementation
could not express.** One changed file invalidated **11 verdicts** but only **1
coverage shard** — the single test that recorded coverage for it. The old design
re-runs every test that touches anything outstanding, which for this edit is
**119 of 327 test classes**, because
the invalidated set contains hub files covered by a third of the suite. Shard-level
coverage caching decouples *whose coverage must be re-measured* (tests touching
changed files) from *whose verdict must be re-proven* (files whose closure contains
a changed file). That decoupling is most of the warm-path win.

**The library** — 41 source files, 902 mutants, 11 threads:

| | mutants | wall clock |
|---|---|---|
| full gate | 902 | 51 s |
| one source file | 12–43 | 2–3 s |
| `--only-covering-test-cases` on the full gate | 902 | 53 s — not worth passing |

**Both projects, nothing changed:** 0.4 s, on digests alone — no coverage driver,
no test process. The cheap question this design exists to make cheap, and the one
a pull request should ask.

Ubiquitous types stay expensive under any scheme — a shared base class invalidates
half the project. That is correct, and not something to engineer around.

---

## 10. What is not yet established

- **The full gate's MSI is not a claim about the application.** The 406 s run was
  driven by merged coverage and so carried the M13 defect: 6,720 generated,
  6,505 killed, 173 escaped — leaving 42 skipped. The *timing* is sound; the score
  is not, and must be re-measured once timings come from a reference capture.
- **The 20× figure is one edit on one project.** It is an observation, not a
  distribution. `doctor` should report the distribution over every file rather
  than let a median stand in for it.
- **No guard on this page exists in executable form.** The mechanics are
  established by experiment; that the assembled system enforces them is not. The
  differential fixture (§7) is what would establish it, and it should be written
  before the pipeline it checks.
- **Narrowing has only been exercised on toy fixtures.** M7, GN1 and the 10 → 3
  mutant collapse were measured on a four-class scratch project, not on either
  real codebase. The application does not need narrowing (§2), so this stays
  unproven until a Tier B/C consumer needs it. Treat `"narrow": true` as
  unimplemented rather than optional.
- **`"signature": "code"` is deferred**, not designed. §3 states the conditions it
  would have to meet; nothing has been built or measured against them.

---

## 11. v1 scope and build order

### Dependency surface

```
php            ^8.4
ext-dom, ext-json
phpunit/phpunit             pinned
infection/infection         pinned
phpunit/php-code-coverage   pinned   ← explicit, not transitive
```

**`php-code-coverage` must be an explicit pinned requirement even though PHPUnit
already brings it.** mule calls its API directly — `ProcessedCodeCoverageData::merge()`,
`setLineCoverage()`, `setTestIds()`, `testIds()`, `Report\Facade::fromSerializedData()`
— so M2, M3 and M4 are assertions about *that* package, not about PHPUnit. Leaving
it transitive means a PHPUnit patch bump can move the serialization format or the
index-keying behaviour under a version nobody chose. The "serialization format 4"
marker in a `.cov` file is the thing to assert against at startup.

**`nikic/php-parser` is not required by v1.** It arrives transitively via Infection
(`^5.6.2`) and is needed only by the static graph, which is deferred. When
narrowing is built, it becomes an explicit pinned requirement for the same reason
as above.

**No static analysis tool, in any form.** PHPStan appears only in Infection's own
`require-dev`, so installing Infection does not install it, and mule adds nothing.
By M15 a configured analyser would convert test gaps into apparent successes, so
this is a prohibition rather than an omission — `--static-analysis-tool` is never
passed, and a consumer configuration requesting one is rejected.

### Scope

This document specifies more than v1 should contain. The split:

**In v1.** Digests and the four-state store (§4) · configuration rendering inside
the project (M11) · monolithic capture, split, persist, dirty-shard re-capture,
attribution merge (§5, M2/M4) · reference timings as their own keyed artifact
(§5) · dynamic dependency edges from the coverage report (§2) · per-file `--filter`
mutation and verdict banking with the stale-edit check (§4) · **guards G1–G9** ·
`mule doctor` (§8) · the differential fixture (§7).

**Not in v1.** Narrowing, in any form: `"narrow": true` is **rejected with an
error**, not silently ignored, so nobody builds on a mode that does not exist. That
removes the static graph, GN1–GN3 and the whole second dependency model.
`"signature": "code"` is likewise rejected. Native per-test cold capture is not
built at all — the cold path is capture-then-split (M4), measured at 34 s against
61 s. **No static analysis tool is ever configured** — by M15 its only effect on a
mule run would be to convert test gaps into apparent successes.

**The release boundary is G9, not G7.** Worth stating because review has twice
drawn it at G7 — the narrowing-only guards used to be numbered G8–G10, so the line
looked like it fell there. It does not. The two guards past G7 are both core and
both arrived late:

- **G8 (proven means killed)** closes the widest hole in the document: without it
  the cache banks timed-out, errored, syntax-error and static-analysis-"killed"
  mutants as proof. It costs one CLI flag and five integer assertions.
- **G9 (discovery enumerates the tree)** is the only guard that can see a file
  nobody has heard of. Without it the gate is silently scoped to whatever the store
  already knew, which gets narrower every time someone adds a file.

Both were added *after* a reviewer assumed the list was complete, which is the
argument for the differential fixture rather than for trusting any boundary drawn
by inspection.

**Order.** Each step is testable before the next exists:

1. Digests, store format, configuration rendering.
2. Monolithic capture → split → persist shards. Assert split shards equal natively
   captured ones (M4) — this is the first real test and it already found one bug
   (M3: read the keys, not the values).
3. Merge attribution; reference timings separately. **Differential fixture here**,
   not at the end: it is the only thing that catches M13, and M13 was invisible to
   every aggregate.
4. Dynamic edges, verdict keys, banking, stale-edit check.
5. G1–G9.
6. `doctor`.

Step 3 before step 4 is deliberate. The pipeline must be shown to reproduce
Infection's verdicts before anything is allowed to skip work on the strength of
them.
