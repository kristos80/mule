# mule

![status: work in progress](https://img.shields.io/badge/status-work%20in%20progress-orange)

> [!WARNING]
> **Work in progress.** The command line, `mule.json`'s keys and the store format
> may change without a major version while this notice is here. If you depend on
> this, pin an exact version and read the changelog. The v4 documentation is
> archived at [`docs/v4-readme.md`](docs/v4-readme.md); none of it applies.

**Mutation testing, only where it can still tell you something.**

---
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=coverage)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=vulnerabilities)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Maintainability Rating](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=sqale_rating)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)
[![Bugs](https://sonarcloud.io/api/project_badges/measure?project=kristos80_mule&metric=bugs)](https://sonarcloud.io/summary/new_code?id=kristos80_mule)

A mutation gate is the most informative test you can run and the slowest. Every
mutant boots the tests that cover it, so re-proving a whole repository to change
one class becomes the slow half of every pull request — and the gate is the first
thing to get dropped from CI.

mule keeps a **committed, content-hashed record of what has been proven**, and
hands the engine only the files whose score can no longer be trusted.

```
$ vendor/bin/mule
Nothing is owed, so nothing was measured — the store describes this tree.

  proven      32 file(s), 1093 mutant(s) killed
  not proven  0 file(s)
  excluded    17 file(s), not gated at all

Proven: every gated file, every mutant killed.
```

That answer took **0.85 s**, with no coverage driver and no test process. The run
that earned it took 70.

---

## What it is for

**The expensive question is "is this file still proven?"** The cheap one is "has
anything that could change the answer moved?" — and that is a few hundred
digests, because the store records what each test file was seen to execute.

A file's verdict rests on four things, and all four are in its key:

1. the file's own bytes;
2. every test file that covers it;
3. every source file *those tests* execute — which is wider than "files this one
   references", and deliberately so;
4. the toolchain: the lock file, the PHPUnit configuration, the mutator set, the
   PHP version, the coverage driver, mule's own version.

Point 3 is the one people want to narrow. Don't: if one test covers both
`Gate.php` and `Decoded.php`, then `Decoded.php`'s mutants run against a test
whose behaviour depends on `Gate.php` — so editing `Gate.php` can flip a
`Decoded.php` verdict even though `Decoded.php` never mentions `Gate`. The
dependency runs through the shared *test*, not through the source.

Measured, that breadth is the difference between a project where one edit
invalidates **2.7 %** of verdicts and one where it invalidates **81 %** — the
same rule over two test-suite shapes. See [`docs/keying.md`](docs/keying.md) §2.

---

## Does your project want this?

**It depends on size, and the answer is measurable before you install anything.**

On a 404-file application, the median edit invalidates 11 files — 2.7 % — and a
warm run is about 20× faster than a cold one. On a 36-file library, a comment-only
change to one file marks 29 of them stale, because almost every test runs through
almost everything. The rule is the same and sound in both cases; the *saving* is
not.

mule is a 36-file library, so it is honest about this: it gates itself at 100 %
MSI and gains nothing from its own cache except the "nothing has changed" answer
above. A project whose full gate takes under a minute does not need this tool.

**`vendor/bin/mule doctor` measures this for you**, from one coverage capture —
no store written, no mutants run. On mule itself it says:

```
Tier C: the median edit re-proves 35 of 39 file(s) — 89 %, which on a project
this size is arithmetic rather than a property of your tests.

You do not need this tool. A project of 39 files has a gate short enough that a
cache has nothing to save, and installing one would add a failure mode and buy
no time.
```

Run it before you commit to this. The figures lead and the tier letter follows,
because every threshold behind it is a judgement rather than a finding.

---

## Install

```bash
composer require --dev kristos80/mule
```

```bash
vendor/bin/mule            # prove whatever is owed, and nothing else
vendor/bin/mule --whole    # re-prove everything, ignoring the store's verdicts
vendor/bin/mule doctor     # would a verdict cache pay for itself here?
vendor/bin/mule --help
```

Exit codes: **0** everything gated is proven · **1** something is not · **2** mule
could not answer the question. Those are three different things, and collapsing
the last two is how a pipeline comes to treat a broken run as a failing gate.

**Commit `.mule/verified.json`.** That is the point: a content-hashed verdict is
not machine-local state, so the next machine and CI inherit the work instead of
re-proving it. Everything machine-local lives in `.mule/cache/`, which mule makes
ignore itself on first run.

**Turning on a coverage driver is the one thing mule cannot do for you**, exactly
as for PHPUnit:

```json
{
	"scripts": {
		"test:mutate": "herd coverage vendor/bin/mule"
	}
}
```

Wrapping *mule* is enough: it forwards its own interpreter and its own loaded
`php.ini` to the coverage run it starts. (Load-bearing — a wrapper that enables
Xdebug through an ini leaves a child started as plain `PHP_BINARY` with no ini and
no driver, which writes a JUnit log, no coverage, and exits 1 with nothing on
stdout. Measured, then fixed.)

---

## Configuration

**You configure mule, and mule configures the engine.** No Infection key and no
PHPUnit key appears in `mule.json`, and a project-level `infection.json5` is
*refused rather than ignored* — because every key that would defeat a guard lives
in one, and a configuration mule did not write is one it cannot digest into the
cache key.

Every key is optional. A project with `src/`, `tests/` and full coverage needs no
`mule.json` at all.

```json
{
	"source": "src",
	"tests": "tests/Unit",
	"support": ["tests/Support", "tests/Fixture"],
	"exclude": ["src/External/PhpFilesystem.php"],
	"mutators": { "@default": true, "@identical": true },
	"min-coverage": 100,
	"threads": "max"
}
```

Nothing machine-local is allowed in it: an absolute path or a `..` fails the run,
because the store is committed and every path in it is project-relative.

**`exclude` and `support` are not interchangeable.** An excluded file is one mule
is not answerable for, so its digest is tracked nowhere and editing it invalidates
nothing. A `support` file's bytes go into the toolchain digest, so editing one
invalidates the whole store. Ask whether the file's bytes can change *another*
file's verdict: if not, `exclude` it; if so, keep it somewhere `support` names.

---

## What it refuses to do

**"Detected by Infection" is not "proven" to mule.** The engine scores a timed-out
mutant, an errored one and one that will not parse as detected; it drops skipped
and ignored mutants out of its denominator; and it treats
`killedByStaticAnalysis` — which means the tests *failed* and a type checker
caught it afterwards — as a success. Every one of those is an unverified mutant
wearing the costume of a verified one, so a run containing any of them is refused
rather than banked.

Nine guards, each with a measured failure behind it. The two that pay for the
rest:

- **A file the engine filed no mutants against fails the run.** Stale coverage
  leaves a file's mutable lines looking unreached, so no mutants are generated and
  the file vanishes from the report. Measured: a stale file sat beside a healthy
  one and the run reported `Mutation Code Coverage: 100%`, MSI 75 %, exit 0, with
  the stale file absent entirely.
- **Discovery walks the tree, never the store.** A store-driven loop can only
  rediscover what it already knows, which is how a file silently stops being
  gated.

And one that is not about Infection at all: **an uncovered line whose statements
happen to be unmutable produces no mutant**, so a file can reach MSI 100 % with
part of it never run. Infection cannot report what it never generated; mule counts
lines against the parser instead.

All nine, with the measurements: [`docs/keying.md`](docs/keying.md) §7.

---

## Documentation

- **[`HOWTO.md`](HOWTO.md)** — the consumer's contract. The three preconditions,
  what happens if you do not meet them, and what to put where.
- **[`docs/keying.md`](docs/keying.md)** — the specification. Twenty verified
  mechanics (M1–M20), the nine guards (G1–G9), what is *not* yet established, and
  the measurements behind every number on this page.

---

## Requirements

PHP 8.4+. PHPUnit `^13.1.14` and Infection `0.35.12` are pinned dependencies
rather than things you install alongside: what a verdict means depends on them, so
they are in the cache key and mule has to know exactly which ones it got.

Verified to resolve alongside **Pest 5** — mule, Pest 5.3.7, PHPUnit 13.4.7 and
Infection 0.35.12 install together. mule runs plain
`PHPUnit\Framework\TestCase` classes through `vendor/bin/phpunit`, so a project
whose tests are those needs no adapter whichever runner it normally uses.

A coverage driver — Xdebug or PCOV — for the capture. The mutation run needs none,
and is deliberately not given one.

## License

MIT.
