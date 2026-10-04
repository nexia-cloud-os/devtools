# Nexia App devtools

Standalone PHP App/resource generators, extracted from Core's existing commands
and stubs. This development package is separate from the runtime SDK. It loads
its own public dependencies and reads App metadata without executing the App's
bootstrap, Composer scripts or providers.

```sh
nexia setup --devtools
nexia create app leave-manager --name LeaveManager --vendor acme --family people
cd leave-manager
nexia make resource Request --label-ko='휴가 신청'
nexia make page LeaveCalendar --label-ko='휴가 달력'
```

The first `nexia create app` argument selects the directory, here `leave-manager`.
PHP namespace: `Nexia\Apps\Acme\LeaveManager`; Composer: `acme/leave-manager`;
frontend: `@acme/leave-manager`. A directory is a checkout location, not the
source of an already registered App's identity. Resource generation reads the
existing manifest and keeps its namespace and table prefix.

`--dry-run` writes nothing. App generation refuses an existing destination.
Resource generation preserves existing files unless `--force` is explicit.
Existing migration files are always preserved, including with `--force`; create a new migration for schema changes.
Resource generation checks planned files, manifest/route insertion points and translation catalogs before writing source. A frontend entry is required, but the old inspector marker is not. These checks prevent deterministic registration/catalog failures from leaving partial output; they are not a filesystem transaction or a concurrent-editor lock.

Review generated domain behavior and migrations before applying them.

Business pages generate a Controller, one React surface, a shared query/response
file and a permission/navigation contributor. They register the API route, lazy
surface, App page route and locale labels without a Model, migration or
ResourceModule. Implement the business query before use: the generated Controller
returns 501 until then rather than reporting a fabricated empty result.
Use `--navigation-group`, `--sort` and `--dry-run`; existing targets are rejected.
Keep `// nexia:pages:end` in the manifest's `pageElementsExtras()` array and the
frontend registration's `overrides` object. New Apps include both insertion
points. All registration and locale checks precede source writes.

Maintainer source checks use a Composer path repository for `../laravel` in an
ignored `composer.local.json`, then `COMPOSER=composer.local.json composer test`.
The source package and public release state are tracked in the workspace plan.

`nexia check (from the App directory)` checks metadata, PHP syntax and flat
`resources/lang/*.json` catalogs without executing App code. It rejects duplicate
translation keys and non-string values. Cross-locale placeholders and rendered
labels still need separate checks. Generators reject symlinks in their source
target trees before writing; installed vendor/node_modules trees are not targets.

Generated Apps use Node.js 22.12+ and npm 11.6.2 (11.x), then build with `npm run build`.
For a local install without changing global tools, use
`npm exec --yes --package=npm@11.6.2 -- npm install`. Generated Docker images include npm 11.6.2.
`dist/frontend` contains ES modules, lazy chunks and a Vite manifest; SDK/React
peers are supplied by the host. The build is separate from source validation.
Generated Docker Compose runs `nexia dev --container` and publishes the preview
only on host loopback. Set `NEXIA_PORT` to a different port for each App when
running several Apps concurrently (default 4310). Build frontend assets before
starting the preview; the CLI never runs App build scripts automatically.
To reproduce the generator-to-build check with an installed Vite 7 CLI:
`node tests/frontend.mjs /absolute/path/to/vite/bin/vite.js`. It generates and
removes its own App; it does not verify a browser host or published dependencies.

New Laravel Apps declare identity, Core compatibility and test paths in
`nexia.json` (schema_version "2", runtime "laravel"). Composer owns PHP
dependencies/autoload, while package.json owns frontend dependencies/build.
Resource generation and validation read the same declaration; do not duplicate
its fields under Composer extra.nexia. Existing legacy packages remain readable.

Resource screens use ListSurface and a RecordSurface with explicit SDK modes.
Read content uses text/badges; editable content uses composed public form inputs,
without a generated information-schema file. Existing split Show/Form screens
must be migrated explicitly before regeneration, even with --force; custom
source is never silently deleted. Adopt the matching Core record-shape routing.

Generated list pages configure ResourceTable directly; they do not add a
pass-through ListSection. RecordSurface owns its editing form in the same file,
with a shared resource Input type for submission. Detail/list requests and shared deletion/cache invalidation live
in the resource queries file. RecordSurface exports the read-only RecordDetails
and the default Inspector adapter, which mounts no page navigation or form state.
The resource contract lazily loads that adapter through the existing registry.
No separate Inspector, Section or per-resource registration file is generated.
Existing App-owned Inspector files and their loader remain intact, including
under --force; replace that loader explicitly when adopting the shared default.

When extending a field, update the migration/model, Controller validation and
ResourceModule request descriptor, shared Record/Input types, and RecordSurface
state/control/read content. Preserve newer input in Agent completion and completed
create route state. Layout changes belong in the shared RecordDetails component;
a draft-only action changes App-owned state and waits for the normal Save path.
No additional SDK state abstraction or screen-specific Agent field list is required.

Check generated tenant/organization resources against installed public SDK types
with `node tests/typescript.mjs /absolute/path/to/node_modules`. The toolchain
must provide TypeScript, Vite types, the SDK and React peers. This creates and
removes a temporary App; it does not validate browser behavior or published
package availability.

Business pages include related read/edit routes sharing one lazy RecordSurface by default. Use `--without-record` to omit them and `--without-navigation` to omit the menu entry. No model or Resource is inferred; implement the generated business controller methods before using them.

Generated PHP/React Apps own menu placement in `nexia.json.navigation`. Each item selects a declared `screen` ID and may set `group`, `subgroup`, `sort`, and `icon`; routes, labels and permissions remain screen-owned. Both generators preflight the menu update and preserve existing placement. `--without-navigation` leaves the screen routable without a menu item. Existing Apps without this field retain their PHP menu until explicitly adopting the JSON list.

Resource generation creates the PHP/React application screens by default. Add `--with-filament` only when you also need Filament administration screens. Omitting that option, including during `--force` regeneration, preserves existing administration files and their manifest registration.

## Public CLI input

Use `nexia create app`, `nexia make resource` and `nexia make page`. The Node CLI collects missing settings, validates supplied options and confirms the plan, then invokes this independently installed PHP generator with `--no-interaction`. PHP command signatures and standalone generator features remain available to their existing callers; they are not compatibility aliases in the public CLI. Translation, ownership and generated runtime contracts are unchanged.
