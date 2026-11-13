# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnilang` `^1.0` with no stability flag, so `composer require omnilang/deepl` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts. Translate
  (`POST /v2/translate`: texts, HTML, formality as prefer_more / prefer_less, a glossary, the
  billed characters, the detected source), languages (`GET /v3/languages?resource=translate_text`),
  multilingual glossaries (`/v3/glossaries`: create, list, get, entries, delete), documents
  (`/v2/document`: multipart upload, status, one download). The key by reference, in the
  Authorization header (`DeepL-Auth-Key`), the Free API for a `:fx` key. policy.json: no published
  request rate, attribution required, data retention by plan. Tests on MockHttp over answers
  written from DeepL's OpenAPI description (3.14.0), each asserting the exact request; PolicyTest,
  BareTest; PHPStan at max, baseline empty.
