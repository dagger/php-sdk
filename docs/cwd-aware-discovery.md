# CWD-aware module discovery

The PHP SDK delegates config discovery to
[`github.com/dagger/polyfill`](https://github.com/dagger/polyfill), then
intersects the discovered directories with the PHP SDK modules registered on
the `Workspace` passed to `modules`.

Discovery returns managed modules at or below the client's current directory
and, when the current directory has no module config, its nearest enclosing
managed module. Both `dagger-module.toml` and legacy `dagger.json` participate
in discovery, so the nearest config wins regardless of filename. Composer
`vendor` directories are excluded.

Each `Mod` exposes two coordinates:

- `rootPath`: stable, workspace-root-relative identity used by generation.
- `path`: current-directory-relative path intended for user-facing listings.

Generation anchors `rootPath` at `/` before resolving the module source. This
keeps generation correct when invoked from a module root or another nested
directory.
