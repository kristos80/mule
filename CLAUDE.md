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

## Preconditions mule relies on and does not enforce

mule keys a source file on its own bytes, its covering tests' bytes, the
co-executed source files' bytes, the declared support boundary, and the
toolchain. That is sound only because the blueprint guarantees unit isolation —
collaborators are injected through interfaces and substituted in unit tests.

**Absence from a coverage report is evidence that a collaborator did not execute,
not proof that it was substituted.** mule records the precondition and reports
what it observed; it does not enforce isolation at runtime and must never be
written as if it could.
