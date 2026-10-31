# php-sdk

A Dagger module for managing Dagger modules that use the PHP SDK.

SDK-specific module authoring (scaffolding new modules, codegen) lives in
modules like this one. The engine drives the SDK: it records a module scope in
`dagger.toml`, sets the workspace cwd to it, and asks this module to generate
the scope through `findClientRoot` and `generateScope`. This module writes the
template files, the module's `dagger-module.toml` and the generated SDK files;
the engine owns the workspace bookkeeping. Dependencies between modules are
module clients, which this SDK does not generate yet; see
[Module clients](#module-clients).

The PHP library and codegen live under [`sdk/`](./sdk), vendored from
dagger/dagger; [`sdk/VENDOR.md`](./sdk/VENDOR.md) records the commit. Generation
writes them into each module with a client generated against
`v1.0.0-beta.21`.

## Engine requirement

This module needs an engine from `v1.0.0-beta.21`. The e2e module's
`entrypoint-floor-check` runs the generated entrypoint on the engine that runs the
check, and the floor is the oldest release it passes on.

Earlier versions of this module documented `v1.0.0-beta.12`. On an older engine,
`dagger generate` and `dagger module init` now stop with a message that names the
engine, including for a module that keeps the built-in runtime. Upgrade the engine
to generate. The engine resolves its built-in PHP runtime from
`github.com/dagger/dagger/sdk/php`, not from this module. A module already on the
built-in runtime therefore still loads on that engine, without this module
generating it. The `enforceEngineFloor` setting turns the refusal off for the floor
check. An engine below the floor is not supported.

## Install

From your workspace root:

```sh
dagger module install github.com/dagger/php-sdk
```

The engine recognizes the SDK interface and records the module as the `php` SDK
in `dagger.toml`. After install, the module is also available in `dagger call`
as `php-sdk`.

Calls that return a `Changeset` will print the diff and prompt you to confirm
before writing anything to your workspace.

## Create a new module

```sh
dagger module init php --name my-module
```

The engine records the module scope in `dagger.toml` and calls `generateScope`,
which seeds the starter template, writes `dagger-module.toml`, the SDK and the
entrypoint in one step. Files already in the module directory are kept.

`--template` picks a starter template under `templates/` (`minimal` is the
default):

```sh
dagger module init php --name my-module --template minimal
```

## Generate SDK files

For every recorded PHP module scope:

```sh
dagger generate
```

For a single module:

```sh
dagger call php-sdk mod --path my-module generate
```

`mod` walks up from `--path` to the nearest module config, supporting both CLI
1.0 `dagger-module.toml` and legacy `dagger.json`. `rootPath` is the stable
workspace-root-relative identity; `path` is relative to the caller's current
directory.

See [`php-sdk.dang`](./php-sdk.dang) for the full type surface.

## Generated entrypoint

Generation writes `sdk/` and a Dang entrypoint in `sdk/entrypoint/`: `main.dang`,
`types.dang`, `build.dang` and `dang.toml`. `dagger-module.toml` names the
entrypoint, and the engine runs a function through it. Commit `sdk/` and
`composer.lock`: a module loaded from Git needs them.

A module moves to the entrypoint when it is self-contained. A module that has any
of the following keeps the runtime in its manifest and gets no `dang.toml`:

- `[[dependencies]]`
- `include`, `exclude`, `blueprint` or `toolchains`
- a `source` other than the module directory
- no `composer.json`, or one without the `./sdk` path repository
- a Composer path or autoload path outside the module
- a Composer `vendor-dir` other than `vendor`
- a runtime other than `php`

To move such a module, remove what keeps it there and run `dagger generate`. A
custom entrypoint stays as it is.

A module already on the generated entrypoint that gains a Composer path outside the
module, or include rules, fails to generate. A generated entrypoint with
`[[dependencies]]` and a `[runtime]` reads as a runtime manifest. With
`[[dependencies]]` and no `[runtime]`, generation and the engine refuse it, because
an entrypoint has no dependency list.

### Git

The engine reads the entrypoint from the module's files, which `.gitignore` filters.
Generation appends rules to the module's `.gitignore` and changes no existing rule:
`!/sdk/` and `!/sdk/**` after any rule other than a `vendor/` rule, and `/vendor/`
when no rule ignores `vendor/`. An ignore rule in a directory above the module still
applies. Remove that rule yourself.

### dang.toml

The SDK manages the Dagger version in `sdk/entrypoint/dang.toml`, and sets it to the
version the module's client is generated against, `v1.0.0-beta.21`. Generation
replaces `dagger = true` or an older version on the `Dagger` import, requires
`dang = "2.1"`, and keeps every other setting. Comments and formatting are lost, and
keys are written in alphabetical order.

Generation fails, and writes nothing, in these cases:

- `dang` declares another language version.
- The `Dagger` import has a `path` or a non-empty `endpoint`.
- Another import declares a different Dagger version.
- An import has a `path` inside `sdk/`, which generation replaces.
- `sdk/entrypoint/dang.toml` exists and the module's manifest does not name the
  entrypoint this SDK wrote.
- `sdk/` exists and is not this SDK's library.
- A `dang.toml` above the entrypoint within the module holds settings that the
  generated file would hide. Files are not merged, so move those settings into
  `sdk/entrypoint/dang.toml`.

Generation never changes a `dang.toml` above the entrypoint.

`dang = "2.1"` is the language version. The version on the import is a Dagger
version, and parsing it needs Dang v2.2.0. Standalone Dang tooling older than v2.2.0
may reject the import.

The generated files carry the same version in two channels:

- `dang.toml`: no release up to `v1.0.0-beta.22` reads it, because those engines copy
  only `.dang` files to evaluate an entrypoint. dagger/dagger#14902, which is open,
  reads it to select the module's API view.
- `DAGGER_CLIENT_VERSION` on the exec that runs the module: dagger/dagger#14881 makes
  the engine read it. `v1.0.0-beta.22` is the first release with that change.

The version stays `v1.0.0-beta.21` until the PHP codegen is ported to the
identifier-casing rules. The release of that cut is not decided. Every v1.0.0
prerelease up to `v1.0.0-beta.21` shares one API view, the view the client is generated
against, and `v1.0.0-beta.22` is the first with a view of its own.

Passing checks on a released engine show what generation writes and that calls work.
They do not show that `dang.toml` selects a view. No check here shows that
`DAGGER_CLIENT_VERSION` selects one.

## Client roots

`findClientRoot` detects the PHP client root containing your current directory:
the nearest `composer.json` at or above it. This is how
`dagger module client add` finds the module you are standing in. The SDK
vendored under a module's `sdk/` has a `composer.json` of its own; from there
the module that owns it answers.

Detection records nothing. The scopes `dagger generate` regenerates are the ones
recorded in `dagger.toml` under `[sdks.php.scopes."<path>"]`.

## Module clients

Generated module clients are not supported yet. `dagger module client add` in a
PHP scope fails and leaves the workspace unchanged. A module's existing
dependencies are kept as they are.

## Migrate a workspace

A workspace set up with a CLI before v1.0.0-beta.12 registers this SDK with a
`[modules.php-sdk.as-sdk]` table, which newer engines ignore. Convert the table
to the new SDK registration, then regenerate:

```sh
dagger ws migrate
dagger generate
```

## Skipping generation

To exclude a directory tree from generation, drop an empty
`.dagger-php-sdk-skip-generate` file at or above the module root. Useful for
fixtures, vendored modules, or anything you don't want regenerated in bulk.

```sh
touch some/fixture/.dagger-php-sdk-skip-generate
```
