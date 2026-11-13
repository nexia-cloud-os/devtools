---
status: published
version: 0.2.18
date: 2026-11-13
title: App manifest
description: New Apps omit the unused core version field.
---

## App manifest

New Apps omit the unused `core_version` field from `nexia.json`. Existing declarations remain accepted; no App migration is required. See [submission and version boundaries](https://developers.nexia.to/docs/building-apps-release-an-app) and the [generator source](https://github.com/nexia-cloud-os/devtools/blob/main/stubs/package-app/nexia.stub).
