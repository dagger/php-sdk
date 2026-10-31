# Vendored PHP library

`src/`, `scripts/`, `tests/`, `composer.json`, `composer.lock`, `LICENSE` and
`phpunit.xml.dist` come from `sdk/php` in dagger/dagger at commit
`2b5e3a9671a5957a76e757d8b02ecf78e2d953ab`. The `v1.0.0-beta.21` snapshot of
that library has no enum registration, which this SDK needs.

Local changes to the vendored files:

- `src/Connection/version.php` declares `1.0.0-beta.21`, the engine version this
  SDK targets, instead of `1.0.0-beta.22`.
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

- `src/Module/`, `bin/dagger-module`, `tests/Unit/Module/`, `.gitignore` and this
  file.

`generated/` is not committed. `Mod` generates it for each module from the schema
of `PhpSdk.targetEngineVersion`, and the e2e module generates it for the unit
tests.
