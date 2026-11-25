# mule

A committed, content-hashed verdict cache for PHP mutation testing: mutate only
the files whose score can no longer be trusted. A wrapper around Infection with
caching, and nothing else.

## Engineering standards

Follow the engineering blueprint at
`/Users/chrisathanasiadis/Documents/www/herd/hermes-2/blueprint/README.md`
(https://github.com/kristos80/engineering-blueprint). Where this file is
narrower than the blueprint, this file wins — it is the blueprint applied to a
single-purpose CLI library rather than to an application.

## The one architectural rule

**Determinism is the aim.** Given the same arguments, the same bytes on disk and
the same exit codes from the processes it starts, mule makes the same decisions,
builds the same command line and writes the same store bytes.

That is possible because **every I/O call in the repository lives in
`src/External/`**, behind an interface, as a wrapper that does one thing:

- An external method is straight-line. Every statement is a call, an assignment
  or a return. It may also throw: a failure guard, `if(…) { throw …; }` with no
  else, for a primitive that returns `FALSE` instead of throwing.
- Anything that decides — a condition that is not a failure guard, a default, a
  loop, a ternary, `??`, `?->`, recovery — belongs in a tested class on the
  other side of the interface.
- **The external layer is the only code that is not unit tested.** Its five
  concrete classes are excluded from coverage in `phpunit.xml` and from the gate
  by `exclude` in `mule.json` — one list drives mutation and mule's reading of
  the coverage report, so those two cannot drift.
  They are exercised by `tests/Integration` against a real temporary directory
  and real child processes.

**Everything else is gated at 100 % line coverage and 100 % MSI**, and is
testable without a disk: the unit suite drives the whole program through
in-memory fakes of the five interfaces.

## Conventions

- **No suppressions, anywhere.** No `@phpstan-ignore`, no `@infection-ignore-all`,
  no `@codeCoverageIgnore`, no `@psalm-suppress`, no PHPStan baseline, no
  `assert()` standing in for a type. An analyser's complaint is fixed at its
  cause. Where the fix would be a runtime narrowing that mutation testing cannot
  kill, prefer a real type: `get_object_vars()` over a `(string)` cast on a
  decoded JSON key, a `Dom\XMLDocument` that throws over a `FALSE` to guard.
- **No custom static-analysis rules**, and therefore no tests for any.
- **Full PHPDoc.** A `@param` for every parameter and, except on constructors, a
  `@return`.
- **`mixed` is narrowed once, where it enters.** `Config\Decoded` is the only
  place that turns decoded JSON into typed values; nothing downstream re-checks.
- **No test exists for a guard an analyser asked for.** A test proves a
  behaviour somebody can ask for.
- **Everything mule throws on purpose is a `Failure`.** Anything else reaching
  the top of `Application` is reported as a fault in mule rather than a mistake
  in the project being gated, so never throw a bare `RuntimeException` from
  `src/`.
- **No covers metadata.** No `#[CoversClass]`, no `#[CoversNothing]`. The
  coverage report is what mule keys itself from, and declaring attribution tells
  PHPUnit to credit a test with one class while hiding everything else it ran
  through — which is precisely the connection the cache needs to see. The cost,
  accepted knowingly: a method exercised only from another class's test now
  counts as covered, so the gates no longer catch a method with no test of its
  own. That became a review matter the day the attributes went, and it is worth
  remembering that it was the gates that caught `Summary::crashed()` the last
  time. Give a new method a case in its own class's test anyway.
- **Unit tests never touch the disk, never `chmod`, never skip.** A test that
  skips when the suite runs as root (as CI does) silently measures a different
  line set than the same gate on this machine.
- Tabs, double-quoted strings, interpolation over concatenation, uppercase
  `TRUE` / `FALSE` / `NULL`, `final readonly` by default.

## Commands

| | |
|---|---|
| `composer test` | the unit suite |
| `composer test:integration` | the external layer against the real thing |
| `composer test:coverage` | the coverage report through Herd's driver; the gate itself is mule's `min-coverage` |
| `composer analyse` | PHPStan at max plus cognitive complexity (9 per method, 40 per class) |
| `composer test:mutate` | the whole-repository mutation gate; banks every file it proves |
| `composer test:mutate:new` | the gate over only what the store cannot vouch for |
| `composer test:mutate:status` | what the store claims, mutating nothing |

**Both tools mule drives are in `require`**, not `require-dev`: a consumer gets
the engine and the runner by installing mule, and neither has a key in
`mule.json`. The cost is a narrower compatibility surface —
`phpunit/phpunit ^12.0 || ^13.0`, so a project on 10 or 11 cannot install mule
as a dev dependency and has to install it as a tool of its own.

`mule.json` holds what defines the gate, in mule's own vocabulary — no PHPUnit or
Infection key appears in it, and the engine's configuration is rendered per run
into the scratch directory. It is committed and byte-identical on every machine,
so it is digested into the store. **Nothing machine-local lives in it**: enabling
a coverage driver is done by wrapping mule (`herd coverage bin/mule full`), and
mule hands its own interpreter and ini to the coverage run it starts.

## Releases

`.github/workflows/release.yml` is the whole pipeline. The `gates` job runs on
pull requests and on pushes to `master`; the `release` job runs only on a push
to `master` and only after the gates pass.

**The pull request's title is what cuts a version.** Master takes only squash
merges and takes the commit subject from the PR title, so the title lands on
master verbatim and is the only thing semantic-release reads. `pr-title.yml`
validates it for that reason: a title that is not conventional fails nothing, it
silently releases nothing.

`fix:` is a patch, `feat:` a minor, a `BREAKING CHANGE:` footer or `feat!:` a
major. `ci:`, `chore:`, `docs:`, `refactor:` and `test:` release nothing, which
is the right answer for a change consumers cannot see. **`perf:` and `revert:`
are not in the allowed list**, and that is deliberate rather than an omission:
both would cut a patch under semantic-release's defaults, so admitting either is
a decision about what this repository releases.

**A release tags, publishes, and then opens a pull request.** The tag and the
GitHub release go out from the workflow — a tag is not a branch, so no ruleset
stands in the way. The changelog does not: `@semantic-release/git` would have
pushed it straight to master, which the ruleset rejects twice over, so it
arrives through `peter-evans/create-pull-request` like any other change. The
consequence, accepted: a tag's tree does not contain its own changelog entry.
The `release` job skips a head commit whose subject starts with
`chore(release):`, which is what keeps merging that PR from releasing again —
verified on v3.0.0's own release merge, where that job reported `skipped`.

**That pull request merges itself** once the gates go green, through
`gh pr merge --auto`. It is the one thing that reaches master without a human
reading it, and it is a changelog the release has already published. Nothing is
bypassed: GitHub still waits for the ruleset, the waiting is simply nobody's job.
Left unmerged it would be harmless, since the tag and the GitHub release are out
and Packagist reads the tag.

**Packagist takes the version from the tag**, through its GitHub integration.
Nothing in the workflow talks to Packagist, and `composer.json` carries no
`version` field — `composer validate --strict` warns against one, and a field a
release has to rewrite is a second copy of something the tag already says.

**A change under `src/` or `tests/` has to carry the store with it.** The gates
run `php bin/mule full` and then `git diff --exit-code .mule/verified.json`, so
run `composer test:mutate:new` and commit `.mule/verified.json` in the same
commit. A stale store fails the gate, and a failed gate cuts no release.

**Master takes no direct pushes.** A ruleset requires a pull request, requires
the gates to pass, requires every commit to be verified, and blocks force pushes
and deletion. Local commits need no signing key: what lands on master is
GitHub's own squash commit, signed with the web-flow key.

**A pull request description must not carry a CI skip directive.** The squash
commit takes its body from the description, and GitHub honours a skip directive
anywhere in a commit message — so a description that merely *mentions* one skips
the whole post-merge run: no gates on the merge commit, and no release.
`pr-title.yml` refuses the five GitHub honours for that reason. Found the hard
way on the pull request that moved the release off a direct push, whose
description explained the directive it was removing and whose merge recorded
zero checks.

## Layout

```
bin/mule                  composition root: autoload, the five wrappers, exit
src/
  Cache/                  Snapshot, Touched, Unproven, Reading, Keying, Declared,
                          Freshness, Standing, Universe, Store, StoreFormat
  Config/                 Configuration — mule's whole vocabulary, naming no other tool
  Console/                Application, Cli, Verb, Summary
  Coverage/               Report, CoveredFile, Reach, Unreached — parsers over bytes, never paths
  Engine/                 Infection, Declaration — the engine's command line and the config it points at
  External/               the five interfaces and their five wrappers
  Json/                   Decoded — the only place mixed is narrowed
  Runner/                 Run, Scratch, Measurement — the test runner's command line
  Gate.php, Project.php
tests/
  Unit/                   mirrors src/; no disk, no chmod, no skips
  Support/                in-memory fakes of the five interfaces
  Integration/            the wrappers, and one end-to-end run of bin/mule
```

Documentation: `README.md` is what the tool is; `docs/decisions.md` is why it is
built this way, including what the rewrite replaced and what each removed
suppression turned into.
