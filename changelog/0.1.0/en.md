---
status: published
version: 0.1.0
date: 2026-10-01
title: Standalone App generators
description: Generate and validate App source without installing Core.
---

# Standalone App development tools

- Added `make:page` and `nexia make:page`: a Controller, shared query type, lazy screen and permission/navigation declaration without a Model or migration. Implement the business query before use; the scaffold returns 501 until then. Existing files or missing registration markers are rejected before writes.

- Generate App, Resource and opt-in signature data source code using the existing Nexia templates without a Core checkout. Vendor and App name produce `Nexia\Apps\Vendor\AppName`; Composer and frontend packages use `vendor/app-key` and `@vendor/app-key`.
- Local validation checks metadata, PSR-4 manifest paths and PHP syntax without booting App code. It does not establish runtime, database or authorization correctness.
- App templates include independent Pest configuration, public-tool Docker/Compose setup, private CLI login storage and a build context that excludes App files and secrets. Existing destinations are not overwritten.
- Consume this package through the matching CLI Native template and generator commands. It is a development dependency, separate from the runtime SDK. Core's legacy generator migration and public package publication remain separate delivery steps.

- App validation checks flat translation JSON structure, duplicate keys and string values. Generation and validation reject symlinked App source or metadata instead of following it to external files.
- Generated Apps have a standalone Vite build for the existing React entry and lazy surfaces. Declared SDK/React peers stay external; environment file loading and public-directory copying are disabled. After dependency installation, the matching CLI starts the Vite build watcher with `nexia dev` locally or in Docker. This module build does not prove browser host or SDK runtime compatibility.

## App request middleware

- Generated App routes use the public `Nexia\Http\Middleware::APP_REQUEST` entry instead of importing host tenancy middleware. Core maps it to the existing web, tenancy, session, authentication and delegation chain; App installation and resource context checks remain in place. Adopt the matching SDK/Core release before generating these routes. Isolated runtime request authentication is a separate adapter requirement.

- Generated resource forms reuse the shared guarded draft binding instead of registering a separate Agent callback. Inputs expose their existing value writer, and multiword resource locate actions use the same camel-case identity as the list. Adopt the accompanying Core/React SDK draft contracts; this does not grant save permission or automatically rewrite existing Apps.
- Resource regeneration preserves existing migration files even with --force. Add a new migration for schema changes; generated ordinary source files still follow the explicit overwrite option.
- Resource generation validates planned source files, manifest/route insertion points and translation catalogs before writing. Invalid registration markers or catalogs leave existing App files unchanged. The unused frontend inspector marker is no longer required. Later filesystem failures are not rolled back automatically.
- Generated detail and edit surfaces distinguish forbidden, missing records and query failures instead of inferring permission from absent data. Failed initial loads offer retry where appropriate; transient refresh failures preserve existing content, while 401/403/404 remove stale content. Adopt the generated query-state handling in existing screens; no server authorization policy changes.

- New App entries supply their App key and lazily discovered resource contracts to the host. The unused frontend inspector marker is no longer generated. Existing Apps can add `resourceContracts: import.meta.glob('./resources/*/*-resource-contract.ts')` to their surface registration after updating Core and the React SDK together.

- Generated App instructions use the public CLI `make:*` commands and the login, registration and work-tab save verification flow. After dependency installation, the matching CLI's `nexia dev` also watches frontend builds without another terminal.

- Generated Apps declare the `nexia-php84-v1` platform contracts and require PHP SDK `^0.7.2` with Core `^0.6.21`. Use the matching Core and isolated runtime adapters; SDK installation alone does not enable these host capabilities. Existing App identities are unchanged.
