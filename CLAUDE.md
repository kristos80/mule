# mule

A committed, content-hashed verdict cache for PHP mutation testing: mutate only
the files whose score can no longer be trusted. A wrapper around Infection with
caching, and nothing else.

**This branch is the v5 rewrite.** `src/` was replaced wholesale. Read
`docs/keying.md` before changing anything in `src/` — it is not background, it
is the specification, and every mechanic in it was established by running the
pinned tools rather than by reading their documentation.

## Read these first, in this order

| | |
|---|---|
| `docs/keying.md` | **the specification.** M1–M20 verified mechanics, G1–G9 guards, GN1–GN3 narrowing-only, §10 what is *not* established, §11 the v1 scope and build order |
| `HOWTO.md` | the contract a consumer accepts: preconditions, and what happens when each is broken |
| `docs/decisions.md` | why the repository is shaped the way it is. Written for v4; the release machinery, the conventions and the external-layer reasoning still hold. Its cache design is superseded by `docs/keying.md` |

## The one thing that matters most

**A cache must never turn uncertainty into a verified verdict.**

Every guard in `docs/keying.md` §7 exists because some indicator that reads as
success turned out to represent less verification:

- a coverage report's `hash` is recomputed at render time, so a report built
  from stale data looks internally consistent (M8)
- merging shard JUnit files inflates test times, and Infection then *silently
  skips* mutants that look slow — absent from every detail array in the JSON
  log, and not counted against MSI (M12, M13)
- MSI counts timed-out, errored and syntax-error mutants as kills, and drops
  skipped and ignored ones out of its denominator (M14)
- `killedByStaticAnalysis` means the tests **failed** to detect the mutant and a
  type checker caught it afterwards — a test gap that raises the score (M15)

So **"detected by Infection" and "proven to mule" are different predicates.**
mule banks only `killed`. Never widen that without a measurement.

## Engineering standards

Follow the engineering blueprint at
`/Users/chrisathanasiadis/Documents/www/herd/hermes-2/blueprint/README.md`
(https://github.com/kristos80/engineering-blueprint). Where this file is
narrower, this file wins — it is the blueprint applied to a single-purpose CLI
library rather than to an application.

**Determinism is the aim.** Given the same arguments, the same bytes on disk and
the same exit codes from the processes it starts, mule makes the same decisions,
builds the same command line and writes the same store bytes.

- **Every I/O call lives behind an interface in `src/External/`**, as a wrapper
  whose every statement is a call, an assignment, a return or a throw. Anything
  that decides — a condition that is not a failure guard, a default, a loop, a
  ternary, `??`, `?->` — belongs in a tested class on the other side. That layer
  is the only code not unit tested; it is exercised by an integration suite
  against a real temporary directory and real child processes.

  **The exclusion lists in `phpunit.xml` and `infection.json5` name the same
  files, and keeping them in step is not housekeeping** — one of them drifting is
  how a file stops being gated without anybody choosing that. A new wrapper goes
  into both, or into neither.
- **Everything else is gated at 100 % line coverage and 100 % MSI.**
- **`mixed` is narrowed once, where it enters.** `Coverage\Capture` is the only
  place a coverage payload stops being `mixed`; nothing downstream re-checks.
- **No suppressions, anywhere.** No `@phpstan-ignore`, no `@infection-ignore-all`,
  no `@codeCoverageIgnore`, no baseline, no `assert()` standing in for a type.
  An analyser's complaint is fixed at its cause.
- **Every refusal carries its own message.** Not for the reader's benefit: a
  guard that falls through to a neighbour's message is a guard no test can tell
  from its neighbour, and therefore a mutant that survives for ever. Measured —
  splitting one message into three turned one unkillable mutant into three dead
  ones.
- **Prefer deleting an over-defensive guard to testing it.** `?? NULL` already
  answers for every input shape, so `is_array($x) && is_array($x["k"] ?? NULL)`
  decided nothing and its `&&` mutant was unkillable. Removing the guard removed
  five mutants. An equivalent mutant is usually the gate telling you the code is
  too defensive.
- **Full PHPDoc.** A `@param` for every parameter and, except on constructors, a
  `@return`. Types match the dependency's own contracts: `php-code-coverage`
  declares `array<int<0, max>, non-empty-string>`, so mule does too rather than
  widening to `list<string>`.
- **Everything mule throws on purpose is a `Failure`.** Anything else reaching
  the top is a fault in mule rather than a mistake in the project being gated.
- Tabs, double-quoted strings, interpolation over concatenation, uppercase
  `TRUE` / `FALSE` / `NULL`, `final readonly` by default.

## Commands

| | |
|---|---|
| `composer test` | the unit suite |
| `composer test:coverage` | the suite through Herd's coverage driver |
| `composer analyse` | PHPStan at max plus cognitive complexity (9 per method, 40 per class) |
| `composer test:integration` | the external layer against a real temporary directory |
| `composer test:differential` | the pipeline against Infection's own verdicts |
| `composer test:mutate` | the mutation gate: Infection at MSI 100 %, `--with-timeouts` |
| `herd coverage bin/mule` | mule gating itself, through its own cache |
| `herd coverage bin/mule doctor` | whether a cache would pay for itself here. It says no, correctly |

**mule gates itself, and it is Tier C about it.** Measured: 32 gated files, 1,093
mutants, 0 unproven — the same figure a direct `infection` run reports, which is
the strongest evidence the pipeline reproduces the engine's verdicts rather than
approximating them. A cold run is 70 s; the "nothing has changed" answer is 0.85 s.

But **a one-file edit re-proves 31 of the 32**, because almost every test here
runs through almost everything. That is a property of **size**, not of the tests:
the application mule was written for has the same per-test footprint and
invalidates 2.7 % per edit. See `docs/keying.md` §2 and §8. So self-hosting buys
the cheap question and nothing else — and it is kept because the cheap question is
worth having and because a tool that will not run on itself is a tool nobody
should trust.

`composer test:mutate` runs Infection directly and is the gate CI uses, because a
gate that depends on mule's own cache being right cannot be what proves mule's
cache is right.

**Never pass `--ignore-msi-with-no-mutations`.** A zero-mutant run exits 1 under
`--min-msi=100` and exits 0 once that flag is added (G5).

## Releases

`.github/workflows/release.yml` is the whole pipeline, and `docs/decisions.md`
records what each part of it cost to get right. In short: the pull request's
title is what cuts a version, master takes only squash merges, a release tags and
publishes and then opens a changelog pull request that merges itself, and
Packagist reads the version from the tag.

**`v5` is a prerelease channel.** `.releaserc.json` lists it with
`prerelease: "next"`, so pushes here publish `5.0.0-next.N` while master stays on
v4 — which lets the rewrite be exercised by a real consumer instead of landing as
one unvalidated merge. The `release` job runs on both branches; the changelog
pull request is master-only, because it targets master and a prerelease's
changelog has no business there.

Remove the `v5` entries from `.releaserc.json` and the workflow's trigger list
when v5 lands on master.

**A pull request description must not carry a CI skip directive.** The squash
commit takes its body from the description and GitHub honours a skip directive
anywhere in a commit message, so a description that merely *mentions* one skips
the whole post-merge run: no gates, no release. `pr-title.yml` refuses the five
GitHub honours for that reason.

## Layout

```
bin/mule          the only file that names a concrete port
src/
  Cache/          Key, Toolchain, Verdict, Lapse, Footprint, Snapshot, Standing,
                  Store, StoreFormat
  Config/         Configuration — mule.json, and the whole vocabulary a consumer has
  Console/        Application, Composition, Assembly, Summary, Prognosis
  Coverage/       Capture, Split, Shard, Merge, Timings, Report, TestResults,
                  Soundness, Reach — the shard pipeline (M2–M4, M19) and G3
  Engine/         Declaration, Outcome, Gate — what mule asks Infection and what
                  it refuses to accept back (G1, G2, G5, G8)
  External/       the ports and their wrappers; the only code not unit tested
  Doctor/         Spread, Tier, Diagnosis, Examination — would a cache pay for
                  itself here? One capture, no store, no mutants (§8)
  Runner/         Universe, Coverage, Engine, Commands, Shards, Measured, Run,
                  Finding — one run, start to finish
  Failure.php     the one exception type
  Project.php     project-relative paths, resolved
tests/
  Unit/           mirrors src/; no disk, no chmod, no skips
  Integration/    the external layer against a real temporary directory
  Differential/   the pipeline against Infection's own verdicts — the only thing
                  that catches M13, which was invisible to every aggregate
  Support/        the in-memory ports a unit test drives the program through
  Fixture/        the differential's project
docs/
  keying.md       the specification
  decisions.md    why the repository is shaped this way
  v4-readme.md    archived; none of it applies
HOWTO.md          the consumer's contract
```

**The composition root is `Console\Composition`, and it is the only place that
knows a path by heart** — `.mule/verified.json` committed, `.mule/cache/` ignored,
`mule.infection.json` at the project root because Infection resolves a
configuration's paths relative to that file's own directory (M11). Every path it
hands downstream is project-relative, and that is a correctness property: the
rendered configuration is digested into the cache key, so an absolute path in it
is a key no other machine can reproduce.

`docs/keying.md` §10 is the honest list of what is still not established —
`doctor`, the end-to-end saving on a large project, and narrowing in any form.
