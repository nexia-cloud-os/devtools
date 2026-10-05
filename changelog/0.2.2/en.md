---
status: published
version: 0.2.2
date: 2026-10-05
title: Portable actor checks and App guidance
description: Catch a common actor contract error before remote preparation.
---

Local validation now rejects direct `$request->user()->getKey()` and `auth()->user()->getKey()` calls with the affected file and replacement. Comments, string examples and App model `getKey()` calls remain valid. This is a targeted check, not complete type or authorization analysis.

Generated App instructions link the public SDK and permission guides and explain actor identity, Resource scope, saved-result refresh and uncertain-write recovery. Existing App instructions are not overwritten.

Run `nexia setup --devtools` to update the development tools, then `nexia check` in your App. Merge relevant guidance into existing AGENTS.md files while preserving project rules. Runtime SDK dependencies are unchanged; verify permission and persistence behavior in the sandbox.
