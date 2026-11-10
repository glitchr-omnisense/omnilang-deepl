# omnilang/deepl

[omnisense/omnilang](https://github.com/glitchr-omnisense/omnilang)'s DeepL, under any PHP host:
the [DeepL API](https://developers.deepl.com).

- **translate** (`POST /v2/translate`): texts and HTML (`tag_handling: html`), from a language or
  detected (`detected_source_language`), formality as a preference (`prefer_more`,
  `prefer_less`), a glossary, the characters billed; the languages of `GET /v3/languages`;
- **glossaries** (`/v3/glossaries`, DeepL's advice for new integrations): made, listed, read,
  deleted, applied to translations and documents;
- **documents** (`/v2/document`): uploaded, followed, downloaded once - docx, pptx, xlsx, pdf,
  html, txt, xliff, srt, markdown and more.

DeepL has no detection call: a translation without a source language says the one it detected.

```php
use Omnilang\Deepl\DeeplFactory;
use Omnilang\Model\{Formality, TranslationQuery};

$deepl = (new DeeplFactory($httpClient))->create(['key' => 'env:DEEPL_API_KEY']);   // by reference
foreach ($deepl->translate(TranslationQuery::text(['Chambre double', 'Petit-déjeuner compris'], 'en-GB', formality: Formality::Formal)) as $t) {
    echo $t->text, ' (', $t->detected, ', ', $t->characters, " characters)\n";
}
```

Options: `key` (required, by reference: `env:NAME` or the value from the application's vault),
`base_uri` (`https://api-free.deepl.com` for a key ending in `:fx`, `https://api.deepl.com`
otherwise), `model_type` (`quality_optimized`, `prefer_quality_optimized`, `latency_optimized`),
`timeout`; `policy`, `usage` (the family's).

**Policy** ([policy.json](policy.json), read on 2026-11-10): no request rate published (429 is
honoured, 456 when the plan's characters are spent); commercial use allowed for the customer's own
purposes, not to build a competing API; **attribution required** - DeepL's name or logo with its
domain beside the output shown to end users; content kept only as technically needed (72 hours at
most for debugging), never used for training on paid plans, but **stored perpetually on the free
Developer plan, where no personal data may be sent**.

See [docs/index.md](docs/index.md). MIT licence.
