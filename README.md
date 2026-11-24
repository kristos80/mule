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

## The record is a pair of hashes, and the second one is the point

A file's mutation score is not a property of the file. It is a property of the
file **and the tests that cover it**. Weaken an assertion in one test and a
mutant in an untouched class starts surviving — so a cache keyed on the source
hash alone would answer "verified" in precisely the case the gate exists to
catch.

Every entry therefore carries two digests: the source file's, and one taken over
the test files that cover it. Either moving makes the file stale.

```json
{
	"src/Cache/Verdicts.php": {"source": "35030ccb…", "tests": "b7ca9700…"}
}
```

Comment out a single assertion in this package's own `SummaryTest`, and mule
marks exactly the one source file that test covers as stale.

**Adding a covering test cannot invalidate anything**, which is why the test
digest is taken over the files covering the source *today* rather than over the
test suite as a whole: a mutant dies when any covering test fails, so a new test
can only kill more of them. Removing or weakening one is what matters, and that
moves the digest. A whole-tree digest would have been sound too, and useless —
nearly every change touches some test.

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

`.mule/verified.json` belongs in your repository. A content-hash verdict is not
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

All three generate coverage, every time: an engine refuses to run without it, it
is the cheap half, and it is what lets the keys be read from a fresh
covering-test set rather than a stored one. **Coverage is the floor on run
time**, and no verb avoids it.

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

**It trusts your gate, not your intent.** Mule banks a file when the engine exits
clean under `--min-msi=100 --min-covered-msi=100`. Gate below 100 and a banked
verdict means "scored at least what you asked for", which is a weaker claim than
this cache's design assumes.

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
