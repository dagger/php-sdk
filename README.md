# php-sdk

A Dagger module for managing Dagger modules that use the PHP SDK.

SDK-specific module authoring (scaffolding new modules, codegen) lives in
modules like this one. Under the CLI 1.0 init contract the engine drives the
SDK: this module exposes `initModule` and `targetRuntime`, and the engine
merges the SDK-owned files with its own workspace bookkeeping. Shared,
language-agnostic operations — editing a module's dependencies or its required
engine version — are owned by the core CLI (`dagger module deps`,
`dagger module engine`) and are not part of this module's surface.

The PHP module runtime (the container that runs PHP modules and the GraphQL ->
PHP codegen) still lives in
[`github.com/dagger/dagger/sdk/php`](https://github.com/dagger/dagger/tree/main/sdk/php);
this module wraps the init/scaffolding ergonomics on top of it.

Backed by [`github.com/dagger/polyfill`](https://github.com/dagger/polyfill).

## Install

From your workspace root:

```sh
dagger install github.com/dagger/php-sdk
```

After install, the module is available in `dagger call` as `php-sdk`.

Calls that return a `Changeset` will print the diff and prompt you to confirm
before writing anything to your workspace.

## Create a new module

With a CLI that supports the 1.0 init contract, the engine dispatches to this
SDK's `initModule`:

```sh
dagger module init php my-module
```

`initModule` only seeds the SDK-owned template files; the engine writes the
module config and workspace entries. Run `generate` afterwards to produce the
generated SDK bindings.

`--template` picks a starter template (`minimal` is the default).

You can also call the function directly for testing. `path` is required (the
engine supplies it in the dispatched path):

```sh
dagger call php-sdk init-module --name my-module --path .dagger/modules/my-module
```

## Generate SDK files

For a single module:

```sh
dagger call php-sdk mod --path my-module generate
```

For every managed PHP SDK module visible from your current directory (skipping
any with a `.dagger-php-sdk-skip-generate` marker at or above the module root):

```sh
dagger call php-sdk generate-all
```

## Discover modules in a workspace

```sh
# Managed PHP SDK modules visible from the current directory
dagger call php-sdk modules root-path path
```

Discovery supports both CLI 1.0 `dagger-module.toml` and legacy `dagger.json`.
It returns the nearest enclosing managed module plus managed modules beneath
the current directory. `rootPath` is the stable workspace-root-relative
identity; `path` is relative to the caller's current directory.

See [`php-sdk.dang`](./php-sdk.dang) for the full type surface.

## Skipping generation

To exclude a directory tree from `generate-all`, drop an empty
`.dagger-php-sdk-skip-generate` file at or above the module root. Useful for
fixtures, vendored modules, or anything you don't want regenerated in bulk.

```sh
touch some/fixture/.dagger-php-sdk-skip-generate
```
