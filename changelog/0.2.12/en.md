---
status: published
version: 0.2.12
date: 2026-10-22
title: Patched PHP baseline
description: Use patched PHP releases for App development.
---

The PHP SDK and generators require PHP 8.5.12 or newer below 9.0; PHP 8.4 is no longer supported. New Apps require PHP ^8.5.12 and SDK ^0.10.5, with Docker and CI set to PHP 8.5.12. Update the development and App runtimes before adopting these requirements. Existing CLI installations should run `nexia setup --devtools` to update the generator. Source updates do not replace deployed containers.

## Usage

- [Quickstart](https://developers.nexia.to/docs/getting-started-quickstart)
