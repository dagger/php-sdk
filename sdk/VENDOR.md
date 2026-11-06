# Vendored PHP library

`src/`, `scripts/`, `tests/`, `composer.json`, `composer.lock`, `LICENSE` and
`phpunit.xml.dist` come from `sdk/php` in dagger/dagger at `v1.0.0-beta.25`,
commit `a45241b20a5e07fbfc5954479ef0b61da843499c`.

Local changes to the vendored files:

- `src/Attribute/ListOfType.php` also targets properties. `DaggerField` already
  reads it from a property, and instantiating it there fails without this target.
- `src/Attribute/Doc.php` also targets properties, for the same reason.
- `src/Codegen/CodeWriter.php` creates its target directory and fails when a write
  fails. The upstream tree commits `generated/`, so it never needs either.
- `tests/Unit/Service/Serialisation/SerialiserTest.php` uses `Id`. `ContainerId`
  exists upstream only as a stale committed class, which a regenerated client lacks.
- `composer.json` autoloads the `DaggerModule` namespace of the test fixtures for
  development.

Files this repository adds, which have no counterpart in dagger/dagger:

- `src/Module/`, `bin/dagger-module`, `tests/Unit/Module/` and this file.

`.gitignore` replaces the upstream one: it ignores `vendor/`, `generated/` and `.cache/`,
not the upstream tree's tooling files.

`src/Module/EngineNaming.php` ports part of
[iancoleman/strcase](https://github.com/iancoleman/strcase) v0.3.0, and keeps that
project's MIT license notice in its header. Generated modules copy `src/` and
`LICENSE` but not this file, so the notice has to travel with the port. The rest of
this library stays under `LICENSE`.

`generated/` is not committed. `Mod` generates it for each module from the schema
of `PhpSdk.targetEngineVersion`, and the e2e module generates it for the unit
tests.
