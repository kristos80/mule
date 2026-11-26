# HOWTO: the bargain mule offers you

mule makes mutation testing fast by not running it twice on the same code. That
is the whole product. It is not a mutation engine, it is not a test runner, and
it owns no opinion about your code that is not written down here.

**Whether it can do that for your project is a measurable property of your test
suite, not a matter of opinion.** Run `mule doctor` before you commit to this;
it answers the question in about forty seconds and writes nothing.

Everything below was measured on two real projects at the pinned tool versions,
not inferred:

| | files | lines | test files | mutants |
|---|---|---|---|---|
| **a 51k-line application** | 613 (404 covered) | 51,662 | 334 | 6,720 |
| **a 41-file library** | 41 (36 covered) | ~3,000 | 28 | 902 |

They land in different tiers, and the difference is the single most important
thing on this page.

---

## 1. The property that decides everything

**How many other files' verdicts does changing one file destroy?**

A file's mutation verdict is only trustworthy while every file that its covering
tests execute is unchanged. So the question is how wide a test's footprint is **as
a share of the project**. Measured from each project's own coverage report:

| | files | a test executes | …share of project | verdicts invalidated per edit |
|---|---|---|---|---|
| the application | 404 | ~5 | **1.3 %** | **11 of 404 — 2.7 %** |
| the library | 29 | ~5 | **17.4 %** | 29 of 36 — **81 %** |

Both projects are written to the same conventions and have the same *absolute*
footprint — about five source files per test. **The difference is size, not style.**
Five files is 17 % of a 29-file project, so a few overlapping tests saturate it;
it is 1.3 % of a 404-file one. Nothing about mocking discipline or code
organisation changes that arithmetic.

Which is good news, because **the need for caching and the benefit from it scale
together**. The library cannot be cached and does not need to be: its whole gate is
51 seconds. The application needs it and gets 437 s → 22 s.

**Run `doctor` first anyway.** It tells you which of the two you are, and if the
answer is "too small to benefit" it will say so rather than sell you a cache.

---

## 2. What you get

Measured on the application, end to end, after an edit to a median-impact file:

| stage | cold (nothing cached) | warm |
|---|---|---|
| coverage capture | 30.8 s | **1 s** (one dirty shard) |
| merge into one report | 3.7 s | 5 s |
| mutation | 6,720 mutants — **406 s** | **16 s** (11 files, 165 mutants) |
| **total** | **437 s** | **22 s** |

That is **about 20×**, and it is an observation rather than a projection: the file
was picked from the project's own coverage report for having the median
invalidation, and then the work was actually done.

And when nothing has changed at all: **0.4 s**, with **no coverage driver and no
test process** — answering "is this project still proven?" is a few hundred
digests. That is the check worth putting on a pull request.

Caching coverage is worth about **30 seconds of every warm run** here. On the
small library it is worth three, which is why the size of your project decides
whether that half of mule matters to you.

One caveat on reading the table: 20× is one edit on one project. The spread is
wide — editing a shared base class invalidates half the project and saves you
almost nothing. `doctor` reports the distribution rather than the median.

---

## 3. The preconditions

Three rules. All three are checked; none is advice.

### P1 — Each test file passes on its own

mule captures coverage per test file. A suite that only passes when run whole
does not qualify.

**Why.** Per-file coverage is the unit mule caches. It is also strictly stricter
than a whole-suite run: shared static state and order dependence surface as
failures instead of hiding.

**How mule checks it.** The capture is the check — a test file that fails is a
failed run.

**If you break it.** The run fails on that file with PHPUnit's own output. This
is usually a real bug, found early.

### P2 — Tests are deterministic

No clocks, no randomness, no network, no filesystem outside a temporary
directory you control.

**Why.** mule caches verdicts. A verdict that was never stable was never worth
caching, and mule cannot tell an unstable test from a changed one.

**How mule checks it.** It cannot, fully. This is the one rule you own. It is
achievable: on the application, coverage attribution was byte-identical across
two full runs under `executionOrder="random"`.

**If you break it.** mule will bank a verdict and then disagree with itself on
the next run, which looks exactly like a mule bug and is the most expensive kind
of problem to chase. Treat a verdict that flaps as a test to fix, not a cache to
clear.

### P3 — The toolchain is pinned, the config is machine-independent

PHPUnit and Infection are mule's own dependencies at exact versions. Your
`mule.json` must contain nothing machine-local — no absolute paths, no
interpreter, no ini. Enable your coverage driver by wrapping the command
(`herd coverage vendor/bin/mule run`), never in config.

**Why.** Every verdict is keyed on the toolchain that produced it. A key
containing your laptop's paths never hits in CI; a toolchain outside the key is a
cache that lies after an upgrade.

**If you break it.** An absolute path in `mule.json` is a hard failure. A
toolchain change is not a failure — it invalidates the whole store once, which is
correct.

That is all mule requires of an ordinary project. **Note what is not here:** no
annotation, no `#[CoversClass]`, no restructuring, no one-test-per-class rule. The
application satisfies all three today, unmodified.

---

## 4. If `doctor` says your footprints are too wide

Some projects — and, more often, some *files* inside a healthy project — have a
footprint wide enough to spoil the cache. On the application the median file
invalidates 11 verdicts but a handful invalidate far more:

```
Controller/AbstractController.php          215 (53%)
Domain/Order/WebsiteCode.php               200 (50%)
Domain/Order/Website.php                   181 (45%)
Domain/Order/OrderReference.php            174 (43%)
```

Those are a shared base class and four value objects that nearly everything
constructs. The width is real, not an artefact: a test of an order controller
genuinely executes `OrderReference`.

**For these, mule offers narrowing — and it is opt-in, per project.** mule
rewrites the coverage report it hands Infection so that each file's mutants face
only its own owner test, and takes dependency edges from a static reference graph
instead of the report. Measured on the library, narrowing cut median invalidation
from 81 % to 11 %.

Narrowing costs you two further preconditions, and they are not free:

- **N1 — one owner test per source file**, found by path:
  `src/Cache/Snapshot.php` ↔ `tests/Unit/Cache/SnapshotTest.php`. A mutable file
  with no owner is never cached and is listed as `unowned` every run.
- **N2 — the owner test reaches all of its own file.** Not "the file is 100 %
  covered" — covered *by its own test*. A line that only runs because another
  class's test walked through it does not count.

**N2 is the rule that exists because breaking it produces a green build on broken
code.** mule narrows by dropping covering-test entries that are not the owner's.
A line left with no owner coverage has nothing for a mutant to attach to, so
Infection silently generates fewer mutants. Measured, on one file with one method
reachable only through another class's test:

| report handed to Infection | mutants | "Mutation Code Coverage" | MSI | exit |
|---|---|---|---|---|
| wide | 10 | 100 % | 50 % | **1** — correctly fails |
| narrowed, N2 broken | **3** | **100 %** | **100 %** | **0** — passes |

Seven mutants vanished, one of them a real survivor, and Infection printed
`Mutation Code Coverage: 100%` both times. So mule treats an orphaned line as a
hard failure and names it:

```
src/Leaf.php:5 is reached only by Demo\Tests\TrunkTest.
It needs a case in Demo\Tests\LeafTest before this file can be mutated or banked.
```

**Expect narrowing to find methods you believed were tested.** The same method,
before and after:

```
wide:      <method name="helper" executable="1" executed="1" coverage="100"/>
narrowed:  <method name="helper" executable="1" executed="0" coverage="0"/>
```

That is not mule breaking your coverage. That is a method with no test of its own.

A third rule applies only while narrowing is on, because the static graph is where
the dependency edges then come from:

- **N3 — collaborators are named, not computed.** No `new $class`, no
  `$class::method()`, no string callables, no container lookups, no
  `class_exists` branching, no reflection-driven construction. `$object::class` is
  fine and whitelisted. A file containing such a construct is refused for caching,
  along with everything whose closure reaches it, and the construct is named with
  its line. The run proceeds, uncached.

---

## 5. What `doctor` tells you

```
vendor/bin/mule doctor
```

Builds the picture from one coverage capture. Writes no store, mutates nothing.

- **Tier A — cache as you are.** Median invalidation low enough that the dynamic
  coverage closure is all mule needs. The application is Tier A today.
- **Tier B — Tier A with hot spots.** Names the files whose footprint spoils
  their own caching, so you can decide whether to narrow, split them, or accept
  the cost.
- **Tier C — caching will not pay here**, with the reason, because the two reasons
  call for opposite responses.

  **C-small** — the project is dense by arithmetic and the full gate is already
  fast. Nothing is wrong with your tests:

  ```
  mule: median invalidation 81% (29 of 36 files per edit).
  mule: cause: 29 source files, ~5 executed per test — 17% of the project each.
  mule: full gate measured at 51s. Expected saving ~0%.
  mule: you do not need this tool yet. Re-run doctor when the project grows.
  ```

  **C-wide** — a large project whose single tests execute a large share of it.
  Here the footprints are worth changing, and `doctor` names them:

  ```
  mule: median invalidation 64% (388 of 610 files per edit).
  mule: cause: these tests each execute more than 10% of the project:
          tests/Feature/CheckoutTest.php (71)
  ```

  The answer to C-wide is to narrow (§4) or to change those tests. **It is not to
  loosen a rule.** Two earlier attempts at this tool produced a perfectly sound
  cache on a C-small project and saved nothing — which was the right result,
  reached for the wrong reason and then explained by blaming the architecture.

---

## 6. What mule will never do

- **Cache something it cannot prove.** Every rule fails loudly or degrades to
  re-running. There is no "assume unchanged" path.
- **Report Infection's aggregate as the answer.** MSI averages over the mutants
  that were *generated*, so a file that silently produced none does not lower it —
  it disappears. mule asserts per file that at least one mutant was produced for
  everything it asked to re-prove.
- **Treat "detected" as "proven".** Infection's MSI counts a timed-out mutant, an
  errored one and even one that would not parse as a kill, and drops skipped and
  ignored mutants out of the denominator entirely. mule banks a file only when
  every one of its mutants was *killed by a test* — so it always passes
  `--with-timeouts`, and a file carrying `@infection-ignore-all` gets a report
  rather than a verdict.
- **Count a static-analysis kill as proof.** If Infection is pointed at a type
  checker, it runs it only on mutants that already **escaped** the tests — so
  `killedByStaticAnalysis` marks a mutation your suite missed, and then adds it to
  the score. mule reports those as test gaps and never banks them, and it
  configures no analyser itself. **Installing mule installs PHPUnit and Infection,
  and nothing else** — no PHPStan, no parser, no analyser. Whatever static analysis
  your project runs is your own business and mule neither needs nor touches it.
- **Pass `--ignore-msi-with-no-mutations`.** Measured: a zero-mutant run exits 1
  under `--min-msi=100` and exits **0** once that flag is added. It will never
  appear in a command mule builds.
- **Trust the `hash` attribute in a coverage report.** It is recomputed from disk
  at render time, so a report built from stale cached data carries a hash matching
  the current file and looks internally consistent while being wrong. Measured: a
  stale entry reported `percent="100.00"` and showed a brand-new, never-executed
  method as `coverage="100"`.

---

## 7. What to put in .gitignore

**Nothing, for mule's own files.** It writes `.mule/cache/.gitignore` containing
`*` on first run, so everything machine-local ignores itself. That is not
tidiness: the shard cache for a 613-file project measures **61 MB**, and the
store sitting beside it at `.mule/verified.json` is committed on purpose. One
`git add .mule` without that file and the repository is 61 MB heavier.

```
.mule/verified.json   commit this — a content-hashed verdict is not
                      machine-local state, so the next machine and CI
                      inherit the work instead of re-proving it
.mule/cache/          ignored by mule, automatically. Shards, merged
                      reports, timings, and the engine's own tmpDir
```

mule places Infection's `tmpDir` and its coverage output inside that cache
rather than at your project root, because it renders the engine's configuration
itself and so gets to choose.

**One thing is yours:** `cacheDirectory` in your `phpunit.xml` — commonly
`.phpunit.cache`. Ignore it, but for hygiene rather than for correctness:
php-code-coverage's cache is content-keyed and PHPUnit's result cache only
affects ordering, which does not move coverage attribution. `doctor` will mention
it if it is tracked. mule will not move it for you.

---

## 8. Minimum config

```json
{
	"source": "src",
	"tests": "tests/Unit",
	"support": ["tests/Support", "tests/Fixture"],
	"exclude": ["src/Infrastructure/Storage/PdoConnection.php"],
	"min-coverage": 100,
	"threads": "max"
}
```

Add `"narrow": true` only after reading §4.

`exclude` names files that are neither mutated nor required to have coverage — thin
I/O wrappers you test another way. Keep the same list in your PHPUnit
`<source><exclude>` so the two cannot drift. No PHPUnit key and no Infection key
appears here: mule renders both configurations itself, which is what lets it put
them in the cache key.
