## [3.1.0](https://github.com/kristos80/mule/compare/v3.0.0...v3.1.0) (2026-11-25)

### Features

* keep what a failing run proved ([#8](https://github.com/kristos80/mule/issues/8)) ([2d45bfe](https://github.com/kristos80/mule/commit/2d45bfea29cd6f833747d761d6df722e80820b9f))

## [3.0.0](https://github.com/kristos80/mule/compare/v2.0.0...v3.0.0) (2026-11-25)

### ⚠ BREAKING CHANGES

* name the mutators as an argument, and require the runner (#4)

### Features

* name the mutators as an argument, and require the runner ([#4](https://github.com/kristos80/mule/issues/4)) ([bc0a20c](https://github.com/kristos80/mule/commit/bc0a20cde68178e8872314ef9605e89861a8cf0f))

### Bug Fixes

* recognise the breaking-change marker in a commit subject ([#5](https://github.com/kristos80/mule/issues/5)) ([885cbe3](https://github.com/kristos80/mule/commit/885cbe3a901d2343ef21c75d9e7ad56130ce940f)), closes [#4](https://github.com/kristos80/mule/issues/4) [#4](https://github.com/kristos80/mule/issues/4) [#4](https://github.com/kristos80/mule/issues/4)

# [2.0.0](https://github.com/kristos80/mule/compare/v1.4.3...v2.0.0) (2026-11-25)


* feat!: report coverage, gate on it, and configure it all through mule.json ([4a5e80a](https://github.com/kristos80/mule/commit/4a5e80a2e155c45d33f06ee558f1227a1dab1b43))


### BREAKING CHANGES

* mule.json's keys are entirely new. `coverage`, `binary` and
`options` are gone, and the {coverage-xml}, {junit} and {tests} placeholders
with them; narrowed coverage runs are now opted into with `narrow`. An
`infection.json5` is no longer read, and `infection/infection` moved from
require-dev to require. Projects configure mule and nothing else, and enable a
coverage driver by wrapping mule rather than by configuring it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>

## [1.4.3](https://github.com/kristos80/mule/compare/v1.4.2...v1.4.3) (2026-11-24)


### Bug Fixes

* compare the declared configuration against the tree, not the store ([c76a8ef](https://github.com/kristos80/mule/commit/c76a8ef82f191debdaa07d83de02f06eb12fea5f))

## [1.4.2](https://github.com/kristos80/mule/compare/v1.4.1...v1.4.2) (2026-11-24)


### Bug Fixes

* unprove every verdict when the gate's configuration changes ([2c3e71d](https://github.com/kristos80/mule/commit/2c3e71d3ea23fee0bcd93d8eff371536399f7e86))

## [1.4.1](https://github.com/kristos80/mule/compare/v1.4.0...v1.4.1) (2026-11-24)


### Bug Fixes

* watch the engine's configuration, and bank nothing that moved mid-run ([3911004](https://github.com/kristos80/mule/commit/3911004b6c7fabeeb29c62a856c7af82848a56ee))

# [1.4.0](https://github.com/kristos80/mule/compare/v1.3.0...v1.4.0) (2026-11-24)


### Features

* run coverage over only the tests that could matter ([fe29199](https://github.com/kristos80/mule/commit/fe291991d8e5d533a4f506ef3e0f28fdf44cc768))

# [1.3.0](https://github.com/kristos80/mule/compare/v1.2.0...v1.3.0) (2026-11-24)


### Features

* answer a run with nothing to do without generating coverage ([331ffc7](https://github.com/kristos80/mule/commit/331ffc74c9a43f0434bed0e96e41cb8c368d0b0f))

# [1.2.0](https://github.com/kristos80/mule/compare/v1.1.0...v1.2.0) (2026-11-24)


### Features

* key a file on everything its tests run, not a digest of their names ([9d9e92a](https://github.com/kristos80/mule/commit/9d9e92ab841361cc1c5f6d3ef6ca944878b8ae54))

# [1.1.0](https://github.com/kristos80/mule/compare/v1.0.2...v1.1.0) (2026-11-24)


### Features

* never bank a file the tests do not reach all of, and say where they stop ([dad04b4](https://github.com/kristos80/mule/commit/dad04b4a7dddbc377bbf0efc5e4a4e4570fb30fc))

## [1.0.2](https://github.com/kristos80/mule/compare/v1.0.1...v1.0.2) (2026-11-24)


### Bug Fixes

* say whether a failure is the project's fault or mule's ([529e3ed](https://github.com/kristos80/mule/commit/529e3ede6e3ff4e61049a6c85074fa7cb62bad6a))

## [1.0.1](https://github.com/kristos80/mule/compare/v1.0.0...v1.0.1) (2026-11-24)


### Bug Fixes

* merge the store under a lock, and create its directory inside it ([2602980](https://github.com/kristos80/mule/commit/26029805c1374efe076be36c287bb544bd44ce48))
* normalise a covering test's path before it becomes a key ([894433d](https://github.com/kristos80/mule/commit/894433d770b71a33b185fe2b432366df4779486b))

# 1.0.0 (2026-11-23)


### Features

* a committed, content-hashed verdict cache for PHP mutation testing ([5aff09c](https://github.com/kristos80/mule/commit/5aff09cfd2833ae343e49a3915d8408b7597971e))
