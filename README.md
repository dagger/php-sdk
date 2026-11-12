# php-sdk

A Dagger module for managing Dagger modules that use the PHP SDK.

SDK-specific module authoring (scaffolding new modules, codegen) lives in
modules like this one. The engine drives the SDK: it records a module scope in
`dagger.toml`, sets the workspace cwd to it, and asks this module to generate
the scope through `findClientRoot` and `generateScope`. This module writes the
template files, the module's `dagger-module.toml` and the generated SDK files;
the engine owns the workspace bookkeeping. Dependencies between modules are
module clients, which this SDK generates into the `sdk/` of a module or a PHP
program; see [Module clients](#module-clients).

The PHP library and codegen live under [`sdk/`](./sdk), vendored from
dagger/dagger; [`sdk/VENDOR.md`](./sdk/VENDOR.md) records the commit. Generation
writes them into each module with a client generated against
`v1.0.0-beta.27`.

## PHP version

A module requires PHP `^8.2`, the floor of the library in `sdk/composer.json`. The
module image builds and runs with PHP 8.4. The library's dev tooling (PHPUnit 12)
needs PHP 8.3 or later. Modules do not need the dev tooling, so their floor stays
`^8.2`.

## Engine requirement

This module needs an engine from `v1.0.0-beta.18`. Every generated entrypoint
declares the `sources` member of the entrypoint contract. `v1.0.0-beta.18` is the
first release with that member. An older engine passes a workspace to `types` and
`call`, which the generated entrypoint does not declare. No check here runs on
`v1.0.0-beta.18`.

Modules target `v1.0.0-beta.27`: the floor only says which engines the SDK and the
entrypoints it generates run on.

Earlier versions of this module documented `v1.0.0-beta.12`. On an older engine,
`dagger generate` and `dagger module init` now stop with a message that names the
engine, including for a module that keeps the built-in runtime. Upgrade the engine
to generate. The engine resolves its built-in PHP runtime from
`github.com/dagger/dagger/sdk/php`, not from this module. A module already on the
built-in runtime therefore still loads on that engine, without this module
generating it. An engine below the floor is not supported.

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

## Function roles

A role attribute next to `#[DaggerFunction]` registers the function for a CLI command. The
SDK rejects a role function that does not match its row in the table. An argument is
optional when it has a default value, a nullable type or `#[DefaultPath]`; others are required.

| Attribute | Command | Return type | Required arguments | Engine API since |
| --- | --- | --- | --- | --- |
| `#[Check]` | `dagger check` | `Container` or `void` | `Workspace` only | [`v0.19.7`](https://github.com/dagger/dagger/blob/v0.19.7/core/schema/module.go) |
| `#[Generate]` | `dagger generate` | non-null `Changeset` | `Workspace` only | [`v0.19.11`](https://github.com/dagger/dagger/blob/v0.19.11/core/schema/module.go) |
| `#[Up]` | `dagger start`, alias `dagger up` | non-null `Service` | `Workspace` only | [`v0.20.4`](https://github.com/dagger/dagger/blob/v0.20.4/core/schema/module.go) |
| `#[Agent]` | `dagger agent` | non-null `LLM` | exactly one required `LLM`; any other required argument is `Workspace` or `Agent` | [`v1.0.0-beta.10`](https://github.com/dagger/dagger/blob/v1.0.0-beta.10/core/schema/module.go) |

The [engine floor](#engine-requirement), `v1.0.0-beta.18`, has all four APIs and commands.
This repository runs no test of a role on the floor engine.

Inside a `#[DaggerObject]` class, with the attributes from `Dagger\Attribute`:
```php
#[DaggerFunction, Check]
public function lint(): Container
{
    return dag()->container()->from('php:8.4-cli')->withExec(['php', '--version']);
}

#[DaggerFunction, Generate]
public function generateFile(): Changeset
{
    return dag()
        ->directory()
        ->withNewFile('generated.txt', 'generated')
        ->changes(dag()->directory());
}

#[DaggerFunction, Up]
public function serve(): Service
{
    return dag()->container()->from('alpine:latest')->asService();
}

#[DaggerFunction, Agent]
public function review(LLM $base): LLM
{
    return $base->withPrompt('Review the code in this module');
}
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
entrypoint, and the engine runs a function through it, including its
[role](#function-roles). Commit `sdk/` and
`composer.lock`: a module loaded from Git needs them.

A module moves to the entrypoint when it is self-contained. A module that has any
of the following keeps the runtime in its manifest and gets no `dang.toml`:

- `[[dependencies]]`
- `include`, `exclude`, `blueprint` or `toolchains`
- `disableDefaultFunctionCaching = true` or a `[[clients]]` table
- an `engineVersion` later than `v1.0.0-beta.27`
- a `source` other than the module directory
- a `composer.json` without the `./sdk` path repository
- a Composer path or autoload path outside the module
- a Composer `vendor-dir` other than `vendor`
- a runtime other than `php`

To move such a module, remove what keeps it there and run `dagger generate`. A
custom entrypoint stays as it is.

A module without `composer.json` also moves to the entrypoint. Generation first
adds the starter template's files that the module does not have, as the engine's
PHP runtime does. The `template` setting selects the template. A new module in a
directory that already has a `composer.json` keeps it and gets no starter files.

A module already on the generated entrypoint that gains a Composer path outside the
module, or include rules, fails to generate. A generated entrypoint with
`[[dependencies]]` and a `[runtime]` reads as a runtime manifest. With
`[[dependencies]]` and no `[runtime]`, generation and the engine refuse it, because
an entrypoint has no dependency list.

Generating the entrypoint fails for an enum whose cases the engine would give one name,
such as `Low` and `LOW`, because the engine would replace one with the other without
an error. The message names the enum and both cases. The failed generation applies none
of its changes: the module's manifest and `sdk/` stay as they were, so a module moving
from the built-in runtime stays on it until you rename one of the cases and update its
references. The engine can still update the workspace's `dagger.lock` when the session
ends. A generated entrypoint gets this check the next time it is generated.

The check names the cases as the engine does for the module's target version. From
`v1.0.0-beta.26` the engine names `Base64` `BASE64`, not `BASE_64`, so `Base64` and
`BASE64` collide there and `Base64` and `BASE_64` do not. For such a target, generation
asks the engine for the names. For a target below `v1.0.0-beta.26`, the check uses the
earlier naming rules. It also uses them when the engine has no `naming` API.

### Git

The engine reads the entrypoint from the module's files, which `.gitignore` filters.
Generation appends rules to the module's `.gitignore` and changes no existing rule:
`!/sdk/` and `!/sdk/**` after any rule other than a `vendor/` rule, and `/vendor/`
when no rule ignores `vendor/`. An ignore rule in a directory above the module still
applies. Remove that rule yourself.

### dang.toml

The SDK manages the Dagger version in `sdk/entrypoint/dang.toml`, and sets it to the
version the module's client is generated against, `v1.0.0-beta.27`. Generation
replaces `dagger = true` or an older version on the `Dagger` import, requires
`dang = "2.1"`, and keeps every other setting. Comments and formatting are lost, and
keys are written in alphabetical order.

Generation fails, and applies none of its changes, in these cases:

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

- `dang.toml`: dagger/dagger#14902 selects the module's API view from its `Dagger`
  import. `v1.0.0-beta.26` is the first release with that change. Its runtime effect
  is not verified by this SDK's checks.
- `DAGGER_CLIENT_VERSION` on the exec that runs the module: dagger/dagger#14881 makes
  the engine read it. `v1.0.0-beta.22` is the first release with that change.

The version is past the identifier-casing cut, which dagger/dagger gates at
`v1.0.0-beta.26`. The engine names the module's types, functions and enum members by
the new rules. From `v1.0.0-beta.22`, each release has an API view of its own, and the
client is generated against the `v1.0.0-beta.27` view.

The `targetVersion` setting, or `--target-version`, pins a module to another release
from `v1.0.0-beta.18` to `v1.0.0-beta.27`, which the client schema,
`DAGGER_CLIENT_VERSION` and `dang.toml` then use. Generation refuses a value that is
not a release, is later than `v1.0.0-beta.27` or is below `v1.0.0-beta.18`, and refuses
a pin on a module that keeps the PHP runtime, which the engine generates for its
manifest's `engineVersion`. The checks here pin only `v1.0.0-beta.24`.

`dagger.toml` records the setting for a module's scope under
`[sdks.<name>.scopes."<path>".settings]`. `dagger generate` passes that record to
the SDK. `mod` reads the record itself when no setting is passed. `dagger call
php-sdk mod --path my-module generate` therefore generates for the same release.
In `mod`, a setting passed to `php-sdk` wins over the record. In `dagger
generate`, the record wins over `[modules.php-sdk.settings]`. `mod` refuses
conflicting or case-colliding `targetVersion` records. It does not check the rest of
the config. `dagger generate` validates the whole config itself.

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

The engine's `dagger module client add`, `dagger module client rm` and
`dagger generate` commands record a module's clients in its scope in
`dagger.toml` and regenerate the module with them. A local client's path is
relative to the directory that holds `dagger.toml`.

Each client generates into `sdk/generated/Clients/<Name>/`, in namespace
`Dagger\Clients\<Name>`, where `<Name>` is the client module's name in PascalCase.
`sdk/composer.json` already autoloads it, so the module's `composer.json` does not
change. A call starts from a static method of the class `Client`, named after the
client module:

```php
use Dagger\Clients\Lister\Client as Lister;

$report = Lister::lister()->lint($source);
```

A client shares the session of `Dagger\Dagger::getClientInstance()`, so core
objects such as a `Dagger\Directory` pass into its calls. A Git client is bound to
its address and to the commit the engine resolved it to; the checks here run
local clients only. Commit `sdk/` with its clients.

A scope without a module is a PHP program, such as a Composer project that calls
Dagger. Its clients generate the same way, against the same core schema as a
module's, without workspace fields such as `currentWorkspace`. Generation also adds
the path repository `./sdk` and the requirement `dagger/dagger: *@dev` to its
`composer.json` with Composer's own commands, which keep the other keys but may
reformat the file, then updates `composer.lock`. When the last client is removed,
generation keeps the Composer wiring and regenerates `sdk/` without clients. A
program with no clients and no `sdk/` from this SDK is left unchanged. A program
loads a local client's module by its path from the root of the workspace it runs
in. Commit `sdk/`, `composer.json` and `composer.lock`.

Generation fails, and applies none of its changes, in these cases:

- The module keeps the PHP runtime; see [Generated entrypoint](#generated-entrypoint).
- A scope without a module and without `composer.json` is given clients.
- A program's `composer.json` names another repository `dagger-sdk`, which
  generation would replace with `./sdk`.
- A program's Composer repositories install `dagger/dagger` from somewhere other
  than `./sdk`, such as an earlier repository or a filter on `./sdk`.
- A client is the module itself, by its path. A module of a workspace opened from
  Git can arrive as a Git client, which this check does not catch.
- Two clients have the same name once case and separators are ignored, such as
  `file-lister` and `FileLister`.
- A client module declares a release on the other side of the identifier-casing
  cut at `v1.0.0-beta.26` from the module's target. The engine names the client
  module by the rules of the release it declares, whatever the API view of the
  session that calls it. Its client uses the names of the module's target.
- A module function takes or returns a client's type. The message is in the
  generation's log; the error itself carries only the exit code.
- A client module adds a field to a core type other than `Query`.

`dagger call php-sdk mod ... generate` does not know a module's clients, and
refuses a module that has some: run `dagger generate`. A module's existing
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
