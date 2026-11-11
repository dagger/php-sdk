# Vendored PHP library

`src/`, `scripts/`, `tests/`, `composer.json`, `composer.lock`, `LICENSE`,
`phpstan-baseline.neon` and `phpunit.xml.dist` come from `sdk/php` in dagger/dagger
at `v1.0.0-beta.27`, commit `400b82d6f3c09ef096d2f59b3e920267b5969bf4`.

Local changes to the vendored files:

- `src/Attribute/ListOfType.php` also targets properties. `DaggerField` already
  reads it from a property, and instantiating it there fails without this target.
- `src/Attribute/Doc.php` also targets properties, for the same reason.
- `src/Codegen/CodeWriter.php` creates its target directory and fails when a write
  fails. The upstream tree commits `generated/`, so it never needs either.
- `src/Codegen/CodeWriter.php`, `src/Codegen/Codegen.php`,
  `src/Codegen/Introspection/NewCodegenVisitor.php` and `src/Command/CodegenCommand.php`
  partition the schema by `@sourceMap(module:)`. `--core`, the default, generates what
  no module contributes. `--client <module>` generates what the module contributes into
  `generated/Clients/<Name>/`, in namespace `Dagger\Clients\<Name>`.
- `src/Client/AbstractClient.php` gains `serve`, which sends `serveModule` once per
  target. `src/Codegen/Introspection/NewCodegenVisitor.php`, `src/Codegen/Codegen.php`
  and `src/Command/CodegenCommand.php` generate a client's `Query` fields as static
  methods of `Dagger\Clients\<Name>\Client`, which serve the target given by
  `--address` and `--pin` on the shared `Dagger::getClientInstance()`.
- `tests/Unit/Service/Serialisation/SerialiserTest.php` uses `Id`. `ContainerId`
  exists upstream only as a stale committed class, which a regenerated client lacks.
- `src/ValueObject/DaggerFunction.php` accepts a check with a required
  `Workspace`, which the engine supplies, and `src/Exception/RegistrationError/Invalid.php`
  says so.
- `composer.json` autoloads the `DaggerModule` namespace of the test fixtures for
  development.

Files this repository adds, which have no counterpart in dagger/dagger:

- `src/Module/`, `src/Client/ModuleTarget.php`, `bin/dagger-module`, `tests/Unit/Module/`,
  `tests/Unit/Codegen/CodegenTest.php`, `tests/Unit/Codegen/Fixture/` and this file.

`.gitignore` replaces the upstream one: it ignores `vendor/`, `generated/` and `.cache/`,
not the upstream tree's tooling files.

`phpcs.xml.dist` replaces the upstream `phpcs.xml`: it also checks `tests/Unit/Module/`.

`phpstan.dist.neon` differs from the upstream file: it also analyses `tests/Unit/Module/`.
The baseline holds the two errors that upstream records in `src/Client/`.
`src/Client/` is unchanged from upstream.

`src/Module/EngineNaming.php` ports part of
[iancoleman/strcase](https://github.com/iancoleman/strcase) v0.3.0, and keeps that
project's MIT license notice in its header. Generated modules copy `src/` and
`LICENSE` but not this file, so the notice has to travel with the port. The rest of
this library stays under `LICENSE`.

`generated/` is not committed. `Mod` generates it for each module from the schema
of `PhpSdk.targetEngineVersion`, and the e2e module generates it for the unit
tests.
