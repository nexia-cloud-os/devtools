---
status: published
version: 0.2.7
date: 2026-10-13
title: Shared resource contract registration
description: Generated entries reuse one resource contract map while keeping screens lazy.
---

Resource generation and new App scaffolds share one eager resource contract map between Inspector and surface registration. Screen modules remain lazy. Regeneration upgrades the recognized older generated registrations while preserving custom entries. Existing Apps can regenerate their entry with this Devtools version; no runtime SDK API change is required.
