# Changelog

## 1.x (unreleased)

- 2026-11-10: the provider written, on omnisense/omnilang and omnisense/contracts. Translate
  (`POST /v2/translate`: texts, HTML, formality as prefer_more / prefer_less, a glossary, the
  billed characters, the detected source), languages (`GET /v3/languages?resource=translate_text`),
  multilingual glossaries (`/v3/glossaries`: create, list, get, entries, delete), documents
  (`/v2/document`: multipart upload, status, one download). The key by reference, in the
  Authorization header (`DeepL-Auth-Key`), the Free API for a `:fx` key. policy.json: no published
  request rate, attribution required, data retention by plan. Tests on MockHttp over answers
  written from DeepL's OpenAPI description (3.14.0), each asserting the exact request; PolicyTest,
  BareTest; PHPStan at max, baseline empty.
