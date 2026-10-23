---
status: published
version: 0.2.12
date: 2026-10-22
title: 보안 패치 PHP 기준
description: 앱 개발에 보안 패치된 PHP 버전을 사용합니다.
---

PHP SDK와 생성기는 PHP 8.5.12 이상 9.0 미만을 요구합니다. PHP 8.4는 더 이상 지원하지 않습니다. 신규 앱은 PHP ^8.5.12와 SDK ^0.10.5를 요구하며 Docker와 CI는 PHP 8.5.12를 사용합니다. 이 조건을 적용하기 전에 개발 환경과 앱 런타임을 업데이트하세요. 기존 CLI 사용자는 `nexia setup --devtools`로 생성기를 갱신해야 합니다. 소스 업데이트만으로 운영 컨테이너가 교체되지는 않습니다.

## 사용 방법

- [빠른 시작](https://developers.nexia.to/ko/docs/getting-started-quickstart)
