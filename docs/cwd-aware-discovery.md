# CWD-aware module discovery

The PHP SDK asks `currentModule.asSDK(workspace: ws).modules` for the modules
registered to it that are relevant to the caller's current directory. The engine
owns both membership and scope selection, so the SDK neither scans config files
nor reconstructs the cwd policy.

Selection returns managed modules at or below the client's current directory
and, when the current directory itself is not registered, its nearest enclosing
managed module.

Each `Mod` exposes two coordinates:

- `rootPath`: stable, workspace-root-relative identity used by generation.
- `path`: current-directory-relative path intended for user-facing listings.

Generation anchors `rootPath` at `/` before resolving the module source. This
keeps generation correct when invoked from a module root or another nested
directory.

`mod` is the separate, path-driven lookup: it walks up from an arbitrary
workspace path to the nearest module config, so it does read `dagger.json` and
`dagger-module.toml` and does not require the module to be registered.
