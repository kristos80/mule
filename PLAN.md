# PLAN — what gets built, and how

Companion to `HANDOFF.md`. That file records what is **known**; this one records
what is **intended**. Read `CLAUDE.md` for the rules both obey.

Every signature below is a commitment, not a sketch. If one turns out wrong when
implemented, change it here in the same commit and say why — a plan that
disagrees with the code is worse than no plan.

---

## 1. The run, as a sequence

One command, one pass, no modes. `bin/mule` builds the container and calls
`VerifyProjectUseCase::execute()`, which does this and nothing else:

| # | step | class | note |
|---|---|---|---|
| 1 | list the tests | `InventoryCommand` → `ProcessRunner` → `InventoryReader` | separate process; `--list-tests-xml` is list-and-exit (mechanic 1) |
| 2 | run the suite once, with coverage | `BatchCommand` → `ProcessRunner` | emits `coverage-xml`, `jsonl`, `junit.xml` together (mechanic 2) |
| 3 | read the report's metadata | `CoverageMetaReader` | gives the project-relative source root; refuses `schemaVersion !== 1` |
| 4 | read per-file reach | `CoverageReader` | executable/executed per gated source |
| 5 | build the ownership relation | `OwnershipReader` | **built** |
| 6 | refuse missing coverage | `CoverageGate` | before any identity is computed; mechanic 7 means Infection will not catch this |
| 7 | digest every source and test file once | `DigestMapReader` | one read per file for the whole run |
| 8 | derive and digest the support boundary | `SupportBoundaryResolver` | the part coverage cannot see (mechanic 5) |
| 9 | digest the toolchain | `ToolchainReader` | |
| 10 | compute an identity per gated source | `IdentityCalculator` + `CoexecutionResolver` | **resolver built** |
| 11 | load banked verdicts | `JsonVerdictLedgerRepository` | a corrupt ledger reads as empty, never as partially valid |
| 12 | partition | `VerificationPlanner` | reusable vs owed |
| 13 | render the engine's configuration | `DeclarationRenderer` | `mule.infection.json` at the project root |
| 14 | mutate the owed files, once | `EngineCommand` → `ProcessRunner` | `--filter` the owed set; absent filter when everything is owed |
| 15 | read the engine's log | `OutcomeReader` | its absence is the only thing treated as the engine failing |
| 16 | refuse anything short of proof | `MutationGate` | §4 below |
| 17 | re-digest, recompute, bank only what still matches | `DigestMapReader`, `IdentityCalculator`, repository | a file edited during the run is not banked |
| 18 | report | `VerificationReport` | |

Steps 1–2 are unconditional. There is no cheap path that skips the test process,
because a source edit can change attribution with no test edit (`HANDOFF.md` §6).

## 2. Classes, with signatures

New only; `HANDOFF.md` §8 lists what exists. Every service is
`final readonly class X implements XInterface`, constructor taking interfaces
only, one public method.

### `Domain/Coverage/`

```php
final readonly class CoverageMeta {                 // anemic
	public function __construct(
		public string $sourceRoot,                  // project-relative
		public int $files,
		public int $executableLines,
		public int $executedLines,
	) {}
}

interface CoverageMetaReaderInterface {
	public function read(string $json): CoverageMeta;          // refuses schemaVersion !== 1
}

final readonly class FileReach {                    // anemic
	public function __construct(
		public string $file,                        // project-relative
		public int $executable,
		public int $executed,
	) {}
}

interface CoverageReaderInterface {
	/** @return array<string, FileReach> keyed by project-relative path */
	public function read(string $jsonl, string $sourceRoot): array;
}
```

`CoverageMetaReader` depends on `RelativePathResolverInterface`. Refusing an
unknown `schemaVersion` is the whole reason it exists: the format is a pinned
dependency's private shape, and reading a future version as if it were version 1
is how a cache starts lying.

### `Domain/Digest/`

```php
final readonly class DigestMap {                    // anemic
	/** @param array<string, string> $digests project-relative path to digest */
	public function __construct(public array $digests) {}
}

interface DigestMapReaderInterface {
	/** @param list<string> $paths project-relative */
	public function read(array $paths): DigestMap;             // one read per file, per run
}
```

Depends on `FileDigesterInterface` and `AbsolutePathResolverInterface`. This
exists so the identity calculator is handed immutable data instead of an I/O
dependency it would hit 404 × 11 times. A missing file digests to a sentinel
(`"gone"`), because a deleted file must change an identity rather than crash the
run.

### `Domain/Identity/`

```php
final readonly class SupportBoundary {              // anemic
	/** @param list<string> $files project-relative, sorted */
	public function __construct(public array $files, public string $digest) {}
}

interface SupportBoundaryResolverInterface {
	public function resolve(TestInventory $inventory): SupportBoundary;
}

final readonly class Toolchain {                    // anemic
	/** @param array<string, string> $packages name to exact version */
	public function __construct(public array $packages, public string $digest) {}
}

interface ToolchainReaderInterface {
	public function read(): Toolchain;
}

final readonly class VerificationId {               // value object
	public function __construct(public string $value) {}
}

interface IdentityCalculatorInterface {
	public function calculate(
		string $source,
		OwnershipMap $ownership,
		DigestMap $digests,
		SupportBoundary $support,
		Toolchain $toolchain,
	): VerificationId;
}
```

`SupportBoundaryResolver` depends on `ConfigurationInterface`,
`FileFinderInterface` and `FileDigesterInterface`. The derivation, restating
`HANDOFF.md` §5: every file under each test root that the inventory does **not**
report as a test file, plus every file under each configured support root; any
extension, since hermes-2 carries 4 `.json` fixtures; skipping dotfiles, because
a `.DS_Store` in the digest breaks P3.

`IdentityCalculator` depends on `CoexecutionResolverInterface` and hashes exactly
the five terms in `HANDOFF.md` §5, in that order, with both lists sorted.

### `Domain/Verdict/`

```php
final readonly class Verdict {                      // anemic
	public function __construct(
		public string $file,
		public string $id,
		public int $mutants,                        // >= 0; zero is a distinct, reported state — §4
	) {}
}

final readonly class VerdictLedger {                // anemic
	/** @param array<string, Verdict> $verdicts keyed by project-relative path */
	public function __construct(public array $verdicts) {}
}

interface VerdictLedgerRepositoryInterface {        // repository: two methods by role
	public function load(): VerdictLedger;          // corrupt or absent reads as empty
	public function save(VerdictLedger $ledger): void;   // whole-ledger replace, never partial
}
```

### `Domain/Outcome/`

```php
final readonly class MutationOutcome {              // anemic
	/**
	 * @param array<string, list<string>> $byFile project-relative path to the kinds filed against it
	 * @param array<string, int> $statistics the engine's own totals
	 */
	public function __construct(public array $byFile, public array $statistics) {}
}

interface OutcomeReaderInterface {
	public function read(string $json): MutationOutcome;
}
```

### `Domain/Gate/`

```php
final readonly class Refusal {                      // anemic
	public function __construct(public string $file, public string $reason) {}
}

interface CoverageGateInterface {
	/** @param array<string, FileReach> $reach @return list<Refusal> */
	public function check(array $reach): array;
}

interface MutationGateInterface {
	/** @param list<string> $owed @return list<Refusal> */
	public function check(MutationOutcome $outcome, array $owed): array;
}
```

Gates **return** refusals; the use case is what throws. A gate that threw could
only ever report the first problem, and a run that names one of nine broken files
costs nine runs to fix.

### `Domain/Plan/`

```php
final readonly class VerificationPlan {             // anemic
	/**
	 * @param array<string, VerificationId> $identities every gated source
	 * @param list<string> $reusable
	 * @param list<string> $owed
	 */
	public function __construct(public array $identities, public array $reusable, public array $owed) {}
}

interface VerificationPlannerInterface {
	/** @param array<string, VerificationId> $identities */
	public function plan(array $identities, VerdictLedger $ledger): VerificationPlan;
}
```

### `Infrastructure/`

One method per interface, so each is stubbed in a line:

```php
interface FileReaderInterface   { public function read(string $absolute): string; }
interface FileWriterInterface   { public function write(string $absolute, string $contents): void; }
interface FileDigesterInterface { public function digest(string $absolute): string; }
interface FileFinderInterface   { /** @return list<string> */ public function find(string $absoluteRoot): array; }
interface FileRemoverInterface  { public function remove(string $absolute): void; }
interface PackageVersionsInterface { /** @return array<string,string> */ public function versions(): array; }

final readonly class ProcessResult { public function __construct(public int $status, public string $output) {} }
interface ProcessRunnerInterface {
	/** @param list<string> $command */
	public function run(array $command, string $workingDirectory): ProcessResult;
}
```

Command builders are pure, so what mule runs is a decision a test reads rather
than a string assembled next to a process:

```php
interface InventoryCommandInterface { /** @return list<string> */ public function build(string $destination): array; }
interface BatchCommandInterface     { /** @return list<string> */ public function build(string $reportDirectory): array; }
interface EngineCommandInterface    {
	/** @param list<string> $files @return list<string> */
	public function build(string $configuration, string $reportDirectory, array $files): array;
}
interface DeclarationRendererInterface { public function render(): string; }
```

`EngineCommand` encodes mechanics 8–11: `-d auto_prepend_file=`,
`--skip-initial-tests`, `--with-timeouts`, both MSI floors, and **never**
`--ignore-msi-with-no-mutations`. An empty `$files` means no `--filter` at all
rather than an empty one — `--filter=` matches nothing, which the gate would read
as the engine filing no mutants against every file at once.

Implementations: `Filesystem`, `ProcessRunner`, `ComposerPackageVersions`,
`JsonVerdictLedgerRepository`, `JsonConfiguration`.

### `UseCase/VerifyProject/`

```php
final readonly class VerificationReport {           // anemic
	/**
	 * @param list<string> $reusable
	 * @param list<string> $verified
	 * @param list<Refusal> $refusals
	 */
	public function __construct(public array $reusable, public array $verified, public array $refusals) {}
}

interface VerifyProjectUseCaseInterface {
	public function execute(): VerificationReport;
}
```

## 3. Build order

Each step ends green at the full bar — tests, PHPStan level 10, 100% line
coverage, 100% MSI with every mutant killed by a test — and is one commit. No
step depends on a later one.

| step | lands | why here |
|---|---|---|
| 1 | `Domain/Coverage/` | the last piece of discovery; needs only what exists |
| 2 | `Domain/Digest/` + `Infrastructure` file interfaces and `Filesystem` | identity needs digests before it needs anything else |
| 3 | `Domain/Identity/` — support boundary, toolchain, calculator | the correctness core; nothing below is meaningful if this is wrong |
| 4 | `Domain/Verdict/` + `JsonVerdictLedgerRepository` | |
| 5 | `Domain/Plan/` | trivial once 3 and 4 exist |
| 6 | `Domain/Outcome/` | |
| 7 | `Domain/Gate/` | needs 1 and 6 |
| 8 | command builders + `DeclarationRenderer` + `ProcessRunner` | pure, so testable before anything is executed |
| 9 | `UseCase/VerifyProject/` | wiring, against stubs of all of the above |
| 10 | `JsonConfiguration`, `config/container.php`, `bin/mule` | first point at which mule runs |
| 11 | validation, §5 | first point at which a claim may be made |

Steps 1–9 are unit-tested against hand-written stubs and never touch a process or
the filesystem. Step 10 is the only wiring, and it is explicit.

## 4. The guarantees the gates enforce

Carried from v5 unchanged, because each was a real defect once.

**Coverage gate**, before any identity is computed:
- A gated source with `executed < executable` is a refusal. Mechanic 7 means
  Infection generates no mutants for uncovered code and the file simply vanishes
  from its log, so a missing-coverage check that ran afterwards would pass.
- **It does not need the enumerated gated set, and here is why.** Mechanics 12–14
  say the report already enumerates every included file, that PHPUnit refuses
  coverage outright rather than writing a partial report, and that absence means
  excluded-or-zero-executable-statements — not "uncovered". So checking every
  returned record is sufficient.
- **The one real hole is exclude drift**, and it gets its own guard. A file listed
  in the project's `<source><exclude>` but not in `mule.json`'s `exclude` is
  gated by mule, absent from the report, and silently unverified. So mule reads
  the project's `phpunit.xml` and refuses when the two lists disagree, naming the
  files. hermes-2 has no drift today — both name the same three — and nothing
  prevents it from acquiring one.

**Mutation gate**, per owed file. Three outcomes, not two:
- **Mutants produced and all killed by a test** → banked.
- **Mutants produced, any other kind** → refusal (below).
- **No mutants produced** → `mutants = 0`, banked, **and named in the report on
  every run**. A file whose executable statements are all unmutatable is a real
  thing; a file that should have mutants and produced none is a silent green, and
  the only honest treatment that is not a hand-maintained list is to keep saying
  so out loud. Aggregate MSI never substitutes for the per-file assertion.

  **Open, needs measurement before step 7 is written:** Infection's log appears
  to omit a file that generated no mutants, which makes "generated none" and
  "never mutated" indistinguishable from the log alone — the same ambiguity
  mechanic 7 created. Check whether `--noop`, `--dry-run` or the summary logger
  enumerates zero-mutant files. **If they do not, this case must fail closed
  rather than bank**, and the plan above changes accordingly.
- Every mutant was **killed by a test**. `escaped`, `timeouted`, `errored`,
  `syntaxErrors`, `ignored`, `notCovered` and `killedByStaticAnalysis` are each
  a refusal naming the file and the kind. A static-analysis kill marks a mutation
  the suite *missed* — Infection runs the analyser only on mutants that already
  escaped — so counting it as proof inverts its meaning.
- The engine's own forbidden statistics are non-zero nowhere:
  `skippedCount`, `timeOutCount`, `syntaxErrorCount`, `ignoredCount`,
  `killedByStaticAnalysisCount`.
- Banking re-reads digests and recomputes identities first. A file edited while
  the engine ran is reported, not banked.

## 5. Validation

Restated from the brief's §8 as concrete cases, not quoted — if the original
wording differs, the original wins.

| | scenario | expected |
|---|---|---|
| V1 | cold run, empty ledger | every gated source verified and banked |
| V2 | second run, nothing changed | every source reusable, no engine process |
| V3 | one source edited | that source owed; its co-executed set unaffected unless shared |
| V4 | one test file edited | every source it owns owed |
| V5 | a support file edited | every source owed |
| V6 | toolchain version changed | every source owed |
| V7 | a new test file added | the sources it covers owed |
| V8 | a test file deleted | the sources it covered owed |
| V9 | a test that covered nothing starts covering something | that source owed, not silently banked |
| V10 | a source loses all coverage | refused, never green |
| V11 | a source produces zero mutants | refused, with the file named |
| V12 | ledger corrupt or truncated | read as empty, full cold run, no crash |

Plus:
- **A differential fixture.** A small project verified by mule and by native
  Infection must reach identical per-file verdicts. This is the only check that
  mule's partition does not change the answer.
- **Measured timings on hermes-2**: cold, warm with nothing changed, and warm
  after one modified unit. Coverage capture is already measured at ~30.6 s and
  will be paid on every run (`HANDOFF.md` §6); the engine's cost is not yet
  measured, and **the ratio between them decides whether v6 is worth having.**
  If the engine turns out cheap relative to capture, say so and stop.

## 5a. The first performance experiment, after the baseline works

**The principle to hold on to: mule should pay for discovery when the evidence
may have changed, not because the program was invoked.** The unconditional
coverage pass (`HANDOFF.md` §6) obeys correctness and violates that principle,
and it costs ~30.6 s of every warm run on hermes-2. v6 as planned therefore has
no sub-second unchanged run, which was the most attractive property of the
original idea. This is the main open concern, not a footnote.

The way out does not need shards, a dependency graph or v5's orchestration — one
project-level fingerprint:

```
DiscoveryId = H( every gated source digest
               + every test file digest
               + supportDigest
               + toolchainDigest
               + the rendered PHPUnit and Infection configurations )
```

If `DiscoveryId` equals the one stored beside the last **successful** capture,
the ownership relation and every verdict can be reused with no PHPUnit process
at all. Soundness rests on P2 (deterministic tests): with every input identical,
re-running can only reproduce the same attribution. Measured support for that
assumption — coverage attribution was byte-identical across two full hermes-2
runs under `executionOrder="random"`.

What it costs, stated plainly so the trade is visible: the ownership relation
becomes persisted state, which brings back a snapshot format, its validation,
and the invalidation rule that it must be discarded whenever `DiscoveryId`
misses or a capture failed halfway. That is exactly the kind of machinery this
rewrite removed, so it is worth doing **only** against a measured baseline, and
only if the engine's cost turns out small enough that the 30 s capture dominates.

**Order: baseline first (steps 1–11), then the §5 timings, then this.** Reducing
a 30-second warm run is worth far more than any hashing micro-optimisation, so it
is the first thing to attempt — and the measurement in §5 decides whether it is
worth attempting at all.

## 6. Deliberately not built

Challenged with "what guarantee or measured gain is lost without it?" — in each
case, none.

| | why not |
|---|---|
| persistent ownership index | subsumed by the unconditional coverage pass (`HANDOFF.md` §6). **Revisit as §5a**, where a single project-level `DiscoveryId` buys back the cheap unchanged run without shards |
| coverage payload split / merge / shards | PHPUnit emits Infection's report directly (mechanic 2) |
| reference timings artefact | `--log-junit` comes out of the same run |
| Composer autoloader class resolution | `--list-tests-xml` answers it (mechanic 1) |
| own AST line counting | `coverage.jsonl` carries executable/executed |
| transitive dependency closure | unsound only in being wasteful: median 11 vs 383 of 404 |
| declared list of fixture-executing production files | less sound and more expensive than the one-hop term, and a hand-maintained list was explicitly rejected |
| narrowing mode, doctor tiers, plugins, extra modes, v5 cache compatibility | not in the brief; each is a second architecture |
| runtime enforcement of unit isolation | a precondition, and not checkable from attribution (mechanic 6) |
