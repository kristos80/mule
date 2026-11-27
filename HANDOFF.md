# HANDOFF — mule v6, session close 2026-11-27

Everything needed to resume without re-deriving anything. Read `CLAUDE.md` first
for the rules, then this.

**Nothing about this project is kept in a local or per-session memory store.**
This repository is the only record. Anything learned that is worth keeping goes
into `CLAUDE.md` (rules) or this file (facts, measurements, decisions) in the
same commit that learns it.

---

## 1. Where things are

| path | state | may I write to it? |
|---|---|---|
| `kristos80/mule3` | **v6, this repo.** Fresh `git init`, branch `main`, one commit `b703823` | yes |
| `kristos80/mule2` | v5. Dirty tree: an uncommitted revert of `05dbfa3`, a G1 change, a trimmed `mule.json` | **no** — read-only reference, user said leave it alone |
| `herd/hermes-2` | the reference application (51k lines, 404 covered sources, 334 test files, 6,720 mutants). Dirty tree | **no** — read-only, output to `/tmp` only |
| `herd/hermes-2/blueprint/README.md` | the Engineering Blueprint, 1,777 lines. `blueprint/CLAUDE.md` is the 76-line summary | no |

v6 inherits **nothing** from v5. Deleted by starting over, not ported: coverage
payload split/merge, shards, reference timings, Composer class resolution, own
AST line counting, `Doctor\*`, all of `Cache\*`.

Investigation artefacts were written to `/tmp/h2`, `/tmp/g1`, `/tmp/p5`, `/tmp/p6`.
**They will not survive a reboot** — every number that mattered is recorded below.

## 2. Toolchain, pinned and verified installed

PHP 8.4.28 · PHPUnit 13.4.8 · phpunit/php-code-coverage 14.5.2 · Infection 0.35.12

## 3. Mechanics — measured, not inferred

Each of these was established by running it. Do not "simplify" any of them
without re-measuring; several were wrong when assumed.

1. **`--list-tests-xml` is list-and-exit.** Given `--coverage-jsonl` at the same
   time it writes the inventory, prints `Wrote list of tests that would have been
   run`, creates no coverage directory, runs no test. Inventory and execution are
   two operations.
2. **One PHPUnit execution emits all three outputs** — `--coverage-xml`,
   `--coverage-jsonl`, `--log-junit` — together.
3. **Infection's report layout** is `<coverage>/coverage-xml/index.xml` plus
   `<coverage>/junit.xml` (`PhpUnitAdapter::COVERAGE_DIR`,
   `JUnitReportLocator::DEFAULT_JUNIT_FILENAME`).
4. **Infection reads that report directly** under `--skip-initial-tests`. mule
   renders no report of its own. Verified on the fixture: 10 mutants, 9 killed,
   1 escaped.
5. **Coverage attribution names only paths under the report's `sourceRoot`.** On
   hermes-2: 404 distinct covered paths, none under `tests/`. It therefore says
   **nothing** about test-support dependencies. This is the trap — do not write
   code that assumes otherwise.
6. **Substituted collaborators are absent from coverage; really-executed ones are
   present.** `ResyncPlanner` (stubbed) absent from `ReconcileCorrectedOrderUseCaseTest`;
   `OrderResnapshotWriter` and `NormalizedOrderMapper` present. This is *evidence*
   of substitution, never proof.
7. **Infection does not mutate uncovered code.** A fixture file with a mutable
   statement no test reached produced `notCoveredCount=0` and appeared nowhere in
   the log. A missing-coverage guard is therefore load-bearing; it cannot be
   replaced by "Infection would have reported it".
8. **Infection refuses to run under `auto_prepend_file`**, and Herd sets one
   (`dump-loader.php`).
9. **`composer/xdebug-handler` drops `-d` overrides on restart.** With xdebug
   loaded, `-d auto_prepend_file=` does not survive, so Infection still refuses.
   The working form is coverage-first, then Infection **without** xdebug.
10. **Infection needs `phpUnit.customPath`** or the restarted process cannot find
    phpunit (`Unable to locate a phpunit executable`).
11. **`--ignore-msi-with-no-mutations` must never be passed.** A zero-mutant run
    exits 1 under `--min-msi=100` and exits 0 once that flag is added.
12. **With `<source><include>` configured, the report enumerates every included
    file even if no test ever loaded it.** php-code-coverage statically analyses
    the include list. Verified on the fixture: `Orphan.php` is loaded by no test,
    has one executable statement, and appears with `executable=1 executed=0`.
13. **Without a source filter, PHPUnit refuses coverage entirely** rather than
    producing a partial report: *"No filter is configured, code coverage will not
    be processed"*, and no `coverage.jsonl` is written at all. There is therefore
    no silently-incomplete-report failure mode.
14. **A file absent from the report is either excluded by the filter or has zero
    executable statements.** Measured on hermes-2: 613 `.php` under `src/`, 3
    excluded by `<source><exclude>`, 404 in the report, **206 absent** — 147
    interfaces, 29 enums, 1 abstract class, 29 bodyless exception classes.
    Absence is *not* evidence of uncovered executable code.
15. **`<source>` carries attributes** (`<source restrictNotices="true" …>` in
    hermes-2), so anything matching the element must not assume a bare tag. A
    pattern that missed it made the report look incomplete when it was not.
16. **`herd coverage` swallows `-d`.** Its ini is
    `~/Library/Application Support/Herd/config/php/84/debug/debug.ini`, and its
    `xdebug.mode` is `debug,develop` — not `coverage`.

### Verified JSONL / XML shapes

```
meta.json       {"schemaVersion":1,"generator":"php-code-coverage 14.5.2","generatedAt":"...",
                 "sourceRoot":"/abs/path/src","branchCoverage":false,"files":404,
                 "executableLines":8629,"executedLines":8629}
tests.jsonl     {"test":"Ns\\FooTest::testBar","covers":{"Domain/Hub.php":["6"],"Leaf.php":["7"]}}
coverage.jsonl  {"file":"Orphan.php","executable":1,"executed":0,"uncovered":["7"],
                 "symbols":[{"name":"Ns\\Orphan::tripled","lines":"6-8","state":"uncovered"}]}
list-tests-xml  <testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>
                  <testClass name="Ns\FooTest" file="/abs/tests/Unit/FooTest.php">
                    <testMethod id="Ns\FooTest::testBar" name="testBar"/>
```

`covers` keys are relative to `meta.sourceRoot`, not to the project root.

## 4. hermes-2 topology — the measured justification for v6

- 404 gated sources, 8,629 executable lines, **100.0% covered**, 4,392 test
  methods, 327 test classes resolved, **0 unresolved**, 334 test files on disk.
- **Sources per test file:** min 1, **median 3**, mean 5.1, p90 11, **max 38**.
  Buckets: 95 own 1 · 96 own 2–3 · 96 own 4–9 · 29 own 10–24 · 11 own 25–49 ·
  **0 own 50+**. Owners per source: median 1, max 65.
- **Widest test files:** `ReconcileCorrectedOrderUseCaseTest` 38 ·
  `ApplyOrderResyncUseCaseTest` 36 · `OrderDossierControllerTest` 33 ·
  `ResyncPlannerTest` 30 · `OrderStorerTest` 29 · `PrintVoucherUseCaseTest` 29.
- **The outliers are not integration tests.** `ReconcileCorrectedOrderUseCase`
  takes 12 collaborators, all behind interfaces, all substituted. Breadth comes
  from (a) fixture construction through real production code —
  `tests/Support/OrderFactory.php` calls `new NormalizedOrderMapper()->map(...)`,
  74 mutants, owned by **19 test files** — and (b) value objects as inputs, 17 of
  38 with zero mutants. One genuine deviation: `OrderResnapshotWriter` (36
  mutants) built concretely despite having an interface; likewise `BarcodeParser`
  and `VoucherEligibility` in `PrintVoucherUseCaseTest`. **Verdict: the suite
  honours the isolation contract and is a valid reference.**
- **Invalidation under the one-hop rule:** median **11 of 404 (2.7%)**, mean 22,
  p90 45, max **215 (53.2%)**; 19 sources (4.7%) invalidate only themselves.
  Editing `NormalizedOrderMapper` invalidates 87 (21.5%); `WebsiteCode` 200;
  `AbstractController` 215.
- **Seven test files cover nothing today**: `SmokeTest`, `CatalogParityTest`,
  `LocaleTest`, `CustomerIdentifierKindTest`, `ResyncLineVerdictTest`,
  `ResyncRefusalReasonTest`, `RecentScanKindTest`.
- Support surface: 485 `.php` under `tests/` of which 334 are test files → 151
  support files, **plus 4 `.json` fixtures**. A `.php`-only support rule is wrong.
- Coverage capture on hermes-2 takes **~30.6 s** (user measured 32.7 s).

### Correction to carry forward

v5's identity rule was **already one hop** — `Snapshot::dependenciesOf()` is "the
union of what its covering tests execute", and `Key::of()` takes those as
`$deps`. There is no transitive closure in v5. An earlier figure of 94.8% was my
own transitive computation and is **not** v5 behaviour. v5's 89% blast radius on
mule came from mule's own `ApplicationTest` owning 39 of 41 sources — a bad
suite, not a bad rule. **v6 keeps v5's identity rule and discards v5's
machinery.** For contrast only: a transitive closure on hermes-2 would be a
median of 383 of 404 (94.8%).

## 5. The identity rule (settled)

```
ID(F) = HASH(
    ("file",       F, digest(F)),
    ("tests",      sorted (T, digest(T)) for T ∈ owners(F)),
    ("coexecuted", sorted (G, digest(G)) for G ∈ ⋃ owns(T), T ∈ owners(F), G ≠ F),
    ("support",    supportDigest),
    ("toolchain",  toolchainDigest),
    ("config",     digest(rendered mule.infection.json) + digest(coverage-relevant phpunit config)),
)
```

**The `config` term was missing until 2026-11-27 and its absence was a real
hole.** The toolchain term carries package versions only, so changing
`mutators` in `mule.json` — enabling `@identical`, say — would have changed which
mutants exist while every identity stayed put, and every stale verdict would have
been reused. The rendered configurations decide what the engine does, so they
belong in the key. Found by comparing against `fallow`, whose result-cache key
includes "scope, runner selection, and targets".

Paths project-relative (P3: a key must hit in CI too). Both lists sorted, because
`executionOrder="random"` changes arrival order on purpose.

The four categories, each with the cheapest **sound** treatment:

| category | treatment | why |
|---|---|---|
| unit under test | `digest(F)` | — |
| substituted collaborators | contribute nothing | they never execute, so they are absent from attribution. Evidence, not proof — isolation is a precondition |
| shared test-support code | one global `supportDigest` | coverage cannot see these at all (mechanic 5). Derived, not declared: every file under the test roots that `--list-tests-xml` does not report as a test file, any extension, skipping dotfiles (a `.DS_Store` in the digest would break P3) |
| real production code run for fixtures / behavioural value objects | the one-hop term | already in attribution; free and exact |

**Decided against a declared support list for the fixture category.** Declaring
`NormalizedOrderMapper` would put it in the support digest and invalidate all 404
rather than 87, finding every such file is manual, and a missed entry is a silent
wrong green. The user's "fixed fixture data" preference is the right instinct in
the *project*: if `OrderFactory` stopped calling the real mapper, the mapper
leaves 19 tests' one-hop sets. mule should report the widest files so that choice
can be made, not force it.

**Known coarseness:** any edit to one of the 489 support files re-verifies all
404. Unverified idea for later — adding the test roots to PHPUnit's
`<source><include>` would attribute support files per test and allow sound
per-test scoping, and because mule renders its own Infection config, what PHPUnit
instruments is independent of what Infection mutates. **That is an experiment, not
a result. Do not implement it as though it were known.**

## 6. Discovery refresh rule (settled)

**The coverage pass is unconditional — every run.** A source edit can change
attribution with no test edit (a new branch, a new call, a file gaining its first
owner). The only alternative is re-running just the affected tests, and per-test
capture *is* v5's sharding.

Consequence: **§3's persistent ownership index is dropped from the design.** Its
only job was avoiding a coverage pass. Fresh discovery detects added, modified,
renamed and deleted files and the covers-nothing transition directly. mule no
longer has a "nothing changed" path that skips the test process; whether that
trade is worth it is a §8 measurement, not a claim.

## 7. Architecture rules — non-negotiable, and broken three times already

From the Blueprint plus explicit user correction:

- **Constructors take dependencies, never data.** Every parameter an interface.
  Values arrive as method arguments, leave as return values.
- **Configuration through `ConfigurationInterface`.** Never a constructor string,
  never `getenv()` in a class that needs it. (`src/` of hermes-2 contains zero
  `getenv`.)
- **No static methods on services.** Static named constructors are allowed on
  anemic data and value objects only — that is the only place hermes-2 uses them
  (`CorrectedOrderReconciliation::applied()`).
- **One public method per service**, named for what it does. A second needs a
  reason in the docblock. hermes-2: 175 classes with exactly one public method.
- Anemic data: public readonly promoted properties, no accessors, no mutators.
- `final readonly class X implements XInterface`.
- Use case per feature: `{Name}UseCaseInterface` with a single `execute()`.
- Colocation by aggregate under `src/Domain/{Aggregate}/`.
- Services stateless and effectively singletons; state only at the I/O boundary,
  travelling inward as immutable data. A half-finished run leaves nothing to
  repair — corrupt banked data is ignored, never migrated; stores are replaced
  wholesale.
- Stubs by default, hand-written in `tests/Support/` (`Stub*`, `Recording*`,
  `Frozen*`). **No `createMock`** — hermes-2 uses none.
- PHPStan level 10, no baseline, no suppressions. Cognitive complexity ≤9 per
  method, ≤40 per class. Prefer a type annotation over a runtime cast or guard.
- Tabs. Uppercase `TRUE`/`FALSE`/`NULL` as literals; lowercase `null` in types.
- Docblocks say **why**, with measured numbers where a number settled it.

## 8. What is built and gated

```
src/Shared/Config/ConfigurationInterface.php
src/Shared/Path/{Relative,Absolute}PathResolver{,Interface}.php
src/Domain/Exception/DiscoveryException.php
src/Domain/Inventory/{TestInventory,InventoryReader,InventoryReaderInterface}.php
src/Domain/Ownership/{OwnershipMap,OwnershipReader,OwnershipReaderInterface}.php
src/Domain/Ownership/{CoexecutionResolver,CoexecutionResolverInterface}.php
tests/Support/{StubConfiguration,StubRelativePathResolver}.php
tests/Unit/...  (5 test classes)
```

**41 tests · 100% line coverage · 100% MSI, all 74 mutants killed by a test ·
PHPStan level 10 clean.** Keep every increment at that bar as it lands, not at
the end.

What the mutation gate caught, as a warning about writing tests that only look
strict: three `TRUE` values nothing read, three redundant `array_values()` after
a `sort()`, and five exception-message assertions matching a substring and so
blind to a concat mutation. Assert whole messages.

## 9. Remaining

**See `PLAN.md`** for the sequence the run executes, every class with its
signature, the build order, the guarantees each gate enforces, the twelve
validation scenarios, and what is deliberately not built. The list below is the
summary only.

1. `Domain/Coverage/` — `CoverageMetaReader` (project-relative `sourceRoot`,
   refuse `schemaVersion !== 1`) and per-file executable/executed from
   `coverage.jsonl`, for the missing-coverage guard that mechanic 7 makes
   necessary.
2. `Domain/Identity/` — `SupportBoundaryResolver` (§5 derivation),
   `ToolchainDigest`, `VerificationId` + `IdentityCalculator`.
3. `Domain/Verdict/` — `Verdict`, `VerdictStoreInterface`; JSON store in
   `Infrastructure/`. Ignore a corrupt store rather than migrate it.
4. `Infrastructure/` behind interfaces — filesystem, process runner, the PHPUnit
   inventory/batch commands and the Infection command (§3 mechanics 1–4, 8–11).
5. Guards: only test-killed mutants count; never accept skipped, ignored,
   timed-out, errored, syntax-error or static-analysis kills; no-executable-
   statements distinguished from zero-mutants; missing coverage never green;
   aggregate MSI never substitutes for per-file; re-read digests before banking;
   fail closed with the file named.
6. `UseCase/VerifyProject/` with a single `execute()`.
7. `config/container.php` (explicit wiring, no auto-wiring) and `bin/mule`.
8. `JsonConfiguration` reading `mule.json`; reject absolute paths (P3).
9. The §8 validation: 12 scenarios, a differential fixture against native
   Infection, and measured cold / warm / one-modified-unit timings on hermes-2.
   **No performance claim without a measurement.**

## 10. Open, unverified

- `--only-covering-test-cases` and `--only-captures` never evaluated.
- Multiple `<source><include>` roots untested.
- `--test-files-file` realpath normalisation (macOS `/tmp` → `/private/tmp`).
  Probably moot: batching makes test-file selection unnecessary.
- The per-test support scoping experiment in §5.
- Whether the unconditional coverage pass is affordable — §9.9 decides it.
- `HOWTO.md` from the previous attempt described a `narrow` mode and a `support`
  config key. Narrowing is extra architecture the brief rules out; the file was
  deleted with the rest of mule3 and should not be restored without a decision.

## 11. Commands

```sh
composer test                 # 41 tests
composer analyse              # PHPStan level 10 + cognitive complexity
composer test:coverage        # line coverage, text
composer test:mutate          # coverage report, then Infection over it
```

`test:mutate` is two steps on purpose (mechanics 8–10): `herd coverage phpunit`
writes `.cov/coverage-xml` and `.cov/junit.xml`, then Infection runs **without**
xdebug under `php -d auto_prepend_file=` with `--skip-initial-tests`. Running
`herd coverage infection` directly fails, and no flag fixes it.
