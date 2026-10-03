---
status: published
version: 0.2.0
date: 2026-10-03
title: Shared-host App generation
description: Generate native Apps for Core 0.7.0 and SDK 0.8.0.
---

# Development tools 0.2.0

Generated Apps require Laravel/React SDK ^0.8.0 and Core ^0.7.0. They declare native v2 metadata, use the shared SDK Vite configuration, and run through the same host contracts in Composer and sandbox environments. Older generated projects are not rewritten automatically.

Page/Resource generation retains explicit read/create/edit modes, shared guarded draft binding, lazy resource registrations and fail-closed error handling. Regeneration preserves migrations; source validation rejects symlinked inputs and invalid insertion markers before writes. The public CLI owns generation and standalone frontend watching.

Use this separately published PHP development package with CLI 0.1.0-alpha.7. It is not a runtime Core dependency. Review the generated changes and verify the App before release.
