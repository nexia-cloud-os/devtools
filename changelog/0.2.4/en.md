---
status: published
version: 0.2.4
date: 2026-10-08
title: Shared frontend build defaults
description: Preserve parallel lazy loading in generated App builds.
---

New Apps build with React SDK 0.8.2 or later and receive shared frontend performance guidance. Generator and React SDK checks now build a generated App and verify dependency preloading, lazy surfaces and external host peers.

Run `nexia setup --devtools` for the generator update. Existing Apps are not overwritten: update the resolved build SDK, retain `nexiaAppConfig`, then rebuild and publish. Shared runtime image assembly does not rebuild previously reviewed assets. Runtime peer requirements and PHP contracts are unchanged.
