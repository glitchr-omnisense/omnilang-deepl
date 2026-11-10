---
title: DeepL
order: 0
---

# omnilang/deepl

Read on 2026-11-10: developers.deepl.com (translate, languages, glossaries v2 and v3 and their
comparison, document, auth, usage limits), DeepLcom/openapi (3.14.0), deepl.com/en/pro-license
("Last update: August 2026") and deepl.com/en/pro-api.

## Options

| Option | Default | |
|---|---|---|
| `key` | required | by reference: `env:DEEPL_API_KEY`, or the value from the application's vault |
| `base_uri` | `https://api-free.deepl.com` for a key ending in `:fx`, else `https://api.deepl.com` | |
| `model_type` | DeepL's | `quality_optimized`, `prefer_quality_optimized`, `latency_optimized` |
| `timeout` | 60 s | |
| `policy`, `usage` | | the family's |

Every request carries `Authorization: DeepL-Auth-Key <key>`; the key is in no message, copy or
trace of a failure.

## Calls

| Method | Request | Read |
|---|---|---|
| `translate()` | `POST /v2/translate`, JSON: `text[]`, `target_lang` (the tag in capitals: `EN-GB`, `ZH-HANS`), `source_lang` (the language alone: `FR`), `formality` (`prefer_more` / `prefer_less`: never an error where the language has none), `tag_handling: html`, `glossary_id` (a source language is required with it), `model_type`, `show_billed_characters: true` | `translations[].text`, `.detected_source_language` (only when no source was given), `.billed_characters` |
| `languages()` | `GET /v3/languages?resource=translate_text` (`/v2/languages` is deprecated) | `lang`, `name`, `usable_as_source`, `usable_as_target`, `features.formality` |
| `createGlossary()` | `POST /v3/glossaries`: `name`, one dictionary (`source_lang`, `target_lang`, TSV `entries`, `entries_format: tsv`) | `glossary_id`, `name`, `dictionaries[]` (`entry_count`), `creation_time` |
| `glossaries()`, `glossary()` | `GET /v3/glossaries`, `GET /v3/glossaries/{id}` (404: null) | as above |
| `glossaryEntries()` | `GET /v3/glossaries/{id}/entries?source_lang&target_lang` | `dictionaries[0].entries` (TSV) |
| `deleteGlossary()` | `DELETE /v3/glossaries/{id}` | 204 |
| `translateDocument()` | `POST /v2/document`, multipart: `file`, `target_lang`, `source_lang`, `formality`, `glossary_id` | `document_id`, `document_key` (the job's secret `key`) |
| `documentStatus()` | `POST /v2/document/{id}`, `document_key` | `status` (queued, translating, done, error), `seconds_remaining`, `billed_characters`, `error_message` |
| `documentResult()` | `POST /v2/document/{id}/result`, `document_key` | the file; DeepL deletes it after one download |

A v3 glossary's id works in `/v2/translate` and `/v2/document` too (DeepL's v2-vs-v3 page); v2
glossary endpoints are not used, since DeepL advises against mixing both.

## Not verified

- The error codes page answered 404; the codes come from the OpenAPI description (400, 403, 404,
  413, 414, 429, 456, 500, 504, 529).
- Whether a key in the body (`auth_key`) is still accepted: not used.
- The API's own free quota: the pricing page read lists the Translator's plans; the terms name
  DeepL API Developer as free of charge.
