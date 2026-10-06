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

The PHP module runtime (the container that runs PHP modules and the GraphQL ->
PHP codegen) still lives in
[`github.com/dagger/dagger/sdk/php`](https://github.com/dagger/dagger/tree/main/sdk/php);
this module wraps the init/scaffolding ergonomics on top of it.

It uses the engine's native `Workspace` and `ModuleSource` APIs directly and
needs an engine from v1.0.0-beta.12.

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
which seeds the starter template, writes `dagger-module.toml` and generates the
SDK files in one step. Files already in the module directory are kept.

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
