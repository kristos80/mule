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
  concrete classes are excluded from coverage in `phpunit.xml` and from mutation
  in `infection.json5`, named file by file in both so the lists stay in step.
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
| `composer test:coverage` | coverage through Herd's driver, gated at 100 % of statements |
| `composer analyse` | PHPStan at max plus cognitive complexity (9 per method, 40 per class) |
| `composer test:mutate` | the whole-repository mutation gate; banks every file it proves |
| `composer test:mutate:new` | the gate over only what the store cannot vouch for |
| `composer test:mutate:status` | what the store claims, mutating nothing |

`mule.json` holds the two things that cannot be inferred here: the `herd coverage`
wrapper this machine needs for a coverage driver, and Infection's options.
**CI deletes `mule.json`** and runs on the inferred defaults — which is also how
the inference path gets exercised.

## Layout

```
bin/mule                  composition root: autoload, the five wrappers, exit
src/
  Cache/                  Verdict, Verdicts, Keying, Store
  Config/                 Configuration
  Console/                Application, Cli, Verb, Summary
  Coverage/               Report, CoveredFile — parsers over bytes, never paths
  Engine/                 Infection — a command line, nothing else
  External/               the five interfaces and their five wrappers
  Json/                   Decoded — the only place mixed is narrowed
  Runner/                 Run, Scratch
  Gate.php, Project.php
tests/
  Unit/                   mirrors src/; no disk, no chmod, no skips
  Support/                in-memory fakes of the five interfaces
  Integration/            the wrappers, and one end-to-end run of bin/mule
```

Documentation: `README.md` is what the tool is; `docs/decisions.md` is why it is
built this way, including what the rewrite replaced and what each removed
suppression turned into.
