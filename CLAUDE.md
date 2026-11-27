# mule

A verdict cache for PHP mutation testing. It discovers which tests cover each
source file, computes an independent verification identity for that file, reuses
a banked proof when the identity matches, and batch-verifies everything else.

## Engineering Standards

Follow the engineering blueprint at:
`/Users/chrisathanasiadis/Documents/www/herd/hermes-2/blueprint/README.md`

Its standards are not advisory here. In particular, and because earlier attempts
at this tool broke each of them:

- **Constructors take dependencies, never data.** Every parameter is an
  interface. Values arrive as method arguments and leave as return values.
  Configuration arrives through `ConfigurationInterface`, never as a constructor
  string and never read from the environment by the class that needs it.
- **No static methods on services.** Static named constructors are allowed on
  anemic data and value objects only (`Verdict::banked()`), which is where the
  blueprint's reference application uses them.
- **One public method per service**, named for what it does. A second public
  method needs a reason written in the docblock.
- **Services are stateless and therefore effectively singletons.** State is read
  and written only at the I/O boundary and travels inward as immutable data.
- **A run that stops halfway leaves nothing to repair.** Corrupted banked data is
  ignored rather than migrated, and a store is only ever replaced wholesale.

## Working rules

Nothing about this project is kept in a local or per-session store. Everything
needed to resume lives in this file and in `HANDOFF.md`. If something is worth
remembering, write it here.

These five were each learned by getting them wrong on this project, and the cost
of re-learning them is days.

### Read the Blueprint before designing a class

The standards above are authoritative, and a real hermes-2 class is the shape to
copy. **v5 (`kristos80/mule2`) is not a style reference; it is the thing being
replaced.** Asked to build v6, I wrote static factories holding parsed data in
the constructor because that is how v5 is written, and the work was stopped:
*"you are designing classes that cannot be tested in isolation, you simply don't
follow blueprints guidelines"*.

Before designing, grep hermes-2 for the shape in question — `static function` in
`src/`, public-method counts per class, how a collaborator is injected. It costs
one command, and assuming has been wrong every time.

### Measure; never explain a result by reasoning

Claims about performance, cache behaviour or why two projects differ must come
from a measurement on a named repository, with the command that produced it.

Twice I generalised from the wrong codebase: first claiming coverage caching was
worth ~3 s and blaming mule2's architecture for its density, when the real target
measured 31.4 s and 2.7% median invalidation; then blaming the I/O-interface
pattern, when per-test footprints measured a median of 3 for both library and
application — identical, the real cause being scale (5/29 = 17% vs 5/404 = 1.3%).

The same applies to tool behaviour. Every mechanic in `HANDOFF.md` §3 was wrong
or unknown when assumed. Run it.

### Do not forecast progress

Never call a piece of work the last one, or say a figure is about to stop being
hand-measured. State only what is finished and verified. Across three successive
pull requests I claimed the next increment was final; the user called it out —
*"this is what you say 3 PRs now"* — and an audit found eight components still
missing.

When asked what remains, enumerate and count it before answering. Describe
finished work in the past tense with its measurement, and leave the rest as a
list with no estimate of how close it is.

### Answer the question that was asked

Asked to help write a correct `mule.json`, I instead refined a guard twice, added
a new config key, and handed over a 127-entry file: *"I DIDN'T ASK YOU TO CREATE
NEW CONFIG FILE. NO SANE PERSON WOULD PUT ALL OF THOSE CLASSES IN A FILE AND KEEP
MAINTAINING IT."* A hand-maintained list is a design smell here, and inventing
scope is worse than a narrow answer.

Where code lives is not mine to choose either — picking a directory unasked drew
*"who told you to use the mule3 folder??"*.

### The reference projects are read-only

`herd/hermes-2` and `kristos80/mule2` must not be modified. Both have dirty
working trees that must stay that way; investigate them with reads only and write
output to `/tmp`. I once destroyed an uncommitted `mule.json` in hermes-2 with
`git checkout` and had to restore it from a transcript.

## Preconditions mule relies on and does not enforce

mule keys a source file on its own bytes, its covering tests' bytes, the
co-executed source files' bytes, the declared support boundary, and the
toolchain. That is sound only because the blueprint guarantees unit isolation —
collaborators are injected through interfaces and substituted in unit tests.

**Absence from a coverage report is evidence that a collaborator did not execute,
not proof that it was substituted.** mule records the precondition and reports
what it observed; it does not enforce isolation at runtime and must never be
written as if it could.
