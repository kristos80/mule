# mule

![status: work in progress](https://img.shields.io/badge/status-work%20in%20progress-orange)

> [!WARNING]
> **Work in progress.** The command line, the `mule.json` keys and the store
> format may change without a major version while this notice is here. `v1.0.0`
> exists because the release flow needs a first version to count from, not
> because the shape is settled — if you depend on this, pin an exact version and
> expect to read the changelog.

**Mutation testing, only where it can still tell you something.**

---
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=coverage)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=vulnerabilities)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Maintainability Rating](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=sqale_rating)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Bugs](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=bugs)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)

A mutation gate is the most informative test you can run and the slowest. On a
real suite it costs several times what coverage costs, because every mutant
boots the tests that cover it — so re-proving the whole repository to change one
class becomes the slow half of every pull request, and the gate is the first
thing to get switched off.

Mule records, per source file, that every mutant in it died, and narrows the
next run to the files whose record can no longer be trusted.

```
composer test:mutate          # 257 mutants, 13s   — the whole package
composer test:mutate:new      #   0 mutants,  2s   — nothing changed
composer test:mutate:new      #   5 mutants,  3s   — one file edited
```

Those are this package gating itself.

## The record is what was proven, and what each test runs

A file's mutation score is not a property of the file. It is a property of the
file **and of everything the tests proving it execute** — weaken an assertion
and a mutant in an untouched class starts surviving; change a collaborator and
a mutant that used to die stops dying, with the file that holds it untouched.

So the store records two things: a digest per proven source file, and per test
file its digest and the source files the coverage report credits it with.

```json
{
	"verified": {
		"src/Cache/Snapshot.php": {"digest": "68cf64b9…"}
	},
	"tests": {
		"tests/Unit/GateTest.php": {"source": "a1b2c3…", "touches": ["src/Gate.php", "src/Cache/Snapshot.php"]}
	}
}
```

A file is stale when its own bytes change, when a test that runs it changes, or
**when anything else that test runs changes**. That last clause is why the
reach is a list rather than a digest: a digest answers "has something moved",
and only a list answers "which files share a test with this one".

The cost is real and worth seeing before you expect miracles. In this
repository, editing `src/Cache/Touched.php` marks **18 of 25 files stale**,
because the tests that prove them run straight through it. A suite of narrow
unit tests narrows far better than one of near-end-to-end tests — and that is
not a flaw in the measurement, it is what a broad test's verdict actually
depends on.

**Adding a covering test cannot invalidate anything**, which is why the reach
is recorded as the report describes it *today* rather than as a digest over the
test suite as a whole: a mutant dies when any covering test fails, so a new
test can only kill more of them.

## Both halves are free

Mutation engines cannot start without a coverage report, and each per-file
report already names the test methods covering each line: that is how an engine
decides which tests to run per mutant. So the covering set costs nothing extra,
and the universe of files is the report's own rather than a list you maintain —
whatever your project excludes from coverage is absent here too, and the two
exclusion lists cannot drift apart.

Turning `Acme\Tests\KeyTest::testOne` into the file that class lives in is the
one thing left, and mule does not work it out either: `vendor/autoload.php`
returns the Composer autoloader your project already configured, and it is asked
directly. A project autoloading its tests with `classmap` or `psr-0` is answered
as correctly as a `psr-4` one, and mule's answer cannot drift from the one your
test run used.

## The store is committed, and that is what engine caches cannot do

`.mule/verified.json` belongs in your repository, and holds four sections: the
files proven, the files the tests do not reach all of, what each test runs, and
a digest for everything else the project autoloads — the last being how a later
run can be sure nothing has *appeared* as well as that nothing has changed.
Only what the project owns is recorded: the autoloader knows a prefix per
installed dependency, and a store with `vendor/` in it would be invalidated by
every `composer install` and differ on every machine.

It belongs in your repository. A content-hash verdict is not
machine-local state: the same bytes give the same answer on the next machine, so
CI inherits the work your laptop proved, and your laptop inherits CI's. One
entry per line, sorted by path, so a rebase conflict is mechanical.

The merge is done **under a lock, with the store read inside it**, so running
the gate in two sessions at once cannot lose either one's verdicts — proved by
eight processes banking two hundred entries between them. The lock file sits in
your system temporary directory, not in the repository, so there is nothing to
ignore. The store itself is still replaced by rename rather than edited in
place: a reader sees one version or the other, never half of one.

Pest ships mutation testing with two caches of its own, and neither substitutes
for this. One caches *generated mutations* keyed on file content — the cheap,
parse-time half. The other caches results, and its only consumers reorder tests
so previously-escaped mutants run first; nothing consults it to skip re-running
a mutant. Both live in `vendor/pestphp/pest-plugin-mutate/.temp/`, keyed on
absolute paths, and are cleared whenever the plugin version changes — so
`composer install` or a fresh CI checkout starts from nothing.

## Install

```bash
composer require --dev kristos80/mule
```

Then add whichever verbs you want to `composer.json`:

```json
{
	"scripts": {
		"test:mutate": "mule full",
		"test:mutate:new": "mule new",
		"test:mutate:status": "mule status"
	}
}
```

Commit `.mule/verified.json`.

## Verbs

| | |
|---|---|
| `mule new` | The gate over only the files the store cannot vouch for. Banks what it proves. Usually what you want in the loop. |
| `mule full` | The gate over the whole project. Banks every file. |
| `mule status` | What the store claims, mutating nothing. |

**A run that has nothing to do generates no coverage at all.** The store
records a digest for every file the project autoloads, so "has anything moved"
is a few hundred hashes rather than a test suite: on this package, `mule new`
with nothing changed is **0.05 s against the 2.4 s** its coverage run costs, and
the gap grows with the suite.

Anything else — a file edited, a file appearing, a file the tests do not reach
all of — and coverage runs as before, because deciding *what* is stale is one
question and proving the rest is another. Mule refuses the shortcut whenever
the store no longer describes the tree in front of it: a recorded file missing,
a file nobody recorded appearing, or one of the files it watches rather than
proves having moved.

There is deliberately no passthrough verb for ad-hoc runs. Mutating a diff of
changed *lines*, or a single class, proves nothing about the files holding them —
so mule could bank nothing and would be adding nothing to your engine's own
command line. Run the engine directly for those.

## The engine is Infection

Mule's decision is the same whichever tool does the mutating, but one engine is
all this needs to make it: Infection is driven against the coverage report mule
has already generated, with `--skip-initial-tests`, so coverage is not produced
twice, and gated with `--min-msi=100 --min-covered-msi=100`.

Mule treats it as **a command, not a dependency**: Infection and a current
PHPUnit both pin `sebastian/diff`, and the two cannot always share a
`composer.json`, so projects in that position install it globally or with PHIVE.
Mule uses `./vendor/bin/infection` where the project has it and `infection` from
your path otherwise.

## Configuration

Mule infers what it can. The source universe comes from the coverage report, the
covering tests from your autoloader, the test runner from whichever binary you
installed, and the store lives at `.mule/verified.json` because it is committed
and there is one of it. `mule.json` is for the rest, and every key is optional:

```json
{
	"coverage": ["herd", "coverage", "./vendor/bin/phpunit", "--coverage-xml={coverage-xml}", "--log-junit={junit}"],
	"binary": "./vendor/bin/infection",
	"options": ["--threads=max", "--no-progress"]
}
```

`coverage` must pass both placeholders; mule substitutes a scratch directory
that is unique per run and removed afterwards, so two sessions can run the gate
at once without treading on each other. The common reason to set it is a
coverage-driver wrapper such as Herd's.

## What it will not do for you

**A failing run banks nothing**, even the files that were clean, and the next run
re-mutates them. The alternative — reading the engine's JSON log to bank partial
progress — can only be pointed at a path by committed config, so two concurrent
runs would write one log file, and a cache that reads another run's results is
worse than one that forgets.

**Timeouts count as kills.** A mutant the clock killed is banked exactly like one
an assertion killed, and the record freezes whichever answer the clock happened
to give that run.

**A file the tests do not reach all of is never banked.** Mule drives the engine
from the coverage report, and an engine works from that report's traces — so a
line nothing runs produces no mutant, and no mutant can fail a gate. A clean
exit therefore says nothing whatever about the parts of a file your tests do not
enter, and recording one would be recording a claim nobody checked. Those files
are re-mutated on every run and reported instead, with the methods named:

```
mule: 24 of 25 files verified, 0 stale, 1 not covered in full.
mule: not banked until the tests reach them:
  src/Cache/Keying.php — testFileOf() lines 94-104, 3 unreached
```

Mule does not *gate* on coverage — that is your coverage gate's job. It reports
what the report it is already holding happens to say.

**It trusts your gate, not your intent.** Mule banks a file when the engine exits
clean under `--min-msi=100 --min-covered-msi=100`. Gate below 100 and a banked
verdict means "scored at least what you asked for", which is a weaker claim than
this cache's design assumes.

### The key is only as complete as your coverage attribution

This one is worth reading before you trust `mule new`, because it is the limit
of the whole idea rather than a rough edge.

Mule learns which tests cover a file from the coverage report, and **a report
only says what your tests declare**. If a test carries `#[CoversClass]`, PHPUnit
credits it with that class and nothing else — even though the test may execute
half your codebase on the way through. Measured in this repository before the
attributes were removed: `GateTest` was credited with **1** file; the identical
test with the attribute deleted was credited with **14**.

The consequence is precise. Editing `Verdicts.php` makes `Verdicts.php` stale,
and leaves `Gate.php` verified — but `GateTest` runs *through* `Verdicts`, so a
`Gate` mutant that used to die may now survive. **`mule new` can pass where
`mule full` would fail**, and no amount of care inside mule can see it: the
connection is absent from the only data it is given.

There is no way to ask PHPUnit for an unrestricted report when the attributes
are there — no flag, and `#[UsesClass]` does not help, because
`php-code-coverage` keeps only the lines you declared as *covered* and uses the
*used* list solely for its strictness check.

So it is a choice about your test suite, not about mule:

- **Declare covers metadata** and narrowing is fast and slightly optimistic. Run
  `mule full` before anything that matters.
- **Declare none** and the report records what each test genuinely touches, so
  the key is complete. Invalidation widens — a file goes stale whenever anything
  its tests run through changes — and that is the honest cost of the stronger
  claim.

**Mule's own suite declares none**, for exactly this reason.

## How it is built

Determinism is the aim, and it is structural: **every I/O call in this
repository lives in `src/External`**, behind an interface, as a wrapper whose
every statement is a call, an assignment, a return or a throw. Those five
classes are the only code excluded from coverage and from mutation, and the only
code without unit tests; they are exercised against a real disk and real child
processes by a separate integration suite.

Everything else — argument parsing, project discovery, configuration, report
parsing, keying, the command line handed to the engine, what is banked and what
is printed — takes those interfaces, so the whole program runs over an array in
memory. Its own gates are **100 % line coverage, 100 % MSI with every one of 257
mutants killed by an assertion rather than by a timeout, PHPStan at max with
cognitive complexity capped at 9 per method, and no suppression of any kind
anywhere**: no `@phpstan-ignore`, no `@infection-ignore-all`, no baseline.

See `CLAUDE.md` for the rules and `docs/decisions.md` for why each of them.

## Requirements

PHP 8.4+, a coverage driver, and PHPUnit or Pest to run your tests.

## Licence

MIT.
