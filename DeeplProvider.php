<?php

namespace Omnilang\Deepl;

use Omnilang\Capabilities;
use Omnilang\DocumentsInterface;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\GlossaryInterface;
use Omnilang\Http\Data;
use Omnilang\Http\JsonClient;
use Omnilang\Http\Multipart;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentJob;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\DocumentStatus;
use Omnilang\Model\Formality;
use Omnilang\Model\Glossary;
use Omnilang\Model\Language;
use Omnilang\Model\LanguagePair;
use Omnilang\Model\SupportedLanguage;
use Omnilang\Model\Translation;
use Omnilang\Model\TranslationQuery;
use Omnilang\TranslatorInterface;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * The DeepL API (developers.deepl.com, OpenAPI 3.14.0 read on 2026-11-10):
 * texts and HTML translated (POST /v2/translate), formality as a
 * preference (prefer_more, prefer_less), the languages of
 * GET /v3/languages (/v2/languages is deprecated), multilingual glossaries
 * (/v3/glossaries: DeepL's advice for new integrations; their ids work in
 * /v2/translate and /v2/document), documents uploaded, followed and
 * downloaded once (/v2/document). DeepL has no detection of its own: a
 * translation without a source language says the one it detected. The key
 * goes in the Authorization header ("DeepL-Auth-Key ...") of every request.
 */
final class DeeplProvider implements TranslatorInterface, GlossaryInterface, DocumentsInterface, DeclaresUsagePolicyInterface
{
    public const POLICY = __DIR__.'/policy.json';

    /** The document types /v2/document takes (developers.deepl.com/docs/resources/usage-limits). */
    public const DOCUMENT_TYPES = ['docx', 'pptx', 'xlsx', 'xlsm', 'pdf', 'htm', 'html', 'txt', 'xlf', 'xliff', 'srt', 'vtt', 'idml', 'xml', 'json', 'yaml', 'yml', 'properties', 'strings', 'md', 'markdown', 'dita', 'mif', 'odt', 'rtf', 'resx', 'jpeg', 'jpg', 'png'];

    public function __construct(
        private readonly JsonClient $json,
        private readonly string $baseUri = 'https://api.deepl.com',
        private readonly ?string $modelType = null,
    ) {
    }

    public function getName(): string
    {
        return 'deepl';
    }

    public function getTitle(): string
    {
        return 'DeepL API';
    }

    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            $this->usagePolicy(),
            translate: true,
            glossary: true,
            documents: true,
            html: true,
            formality: true,
            glossaryInTranslation: true,
            glossaryCreate: true,
            asyncDocuments: true,
            detectsSource: true,
            documentTypes: self::DOCUMENT_TYPES,
        );
    }

    public function translate(TranslationQuery $query): array
    {
        $glossary = $query->glossaryFor($this->getName());
        if (null !== $glossary && null === $query->source) {
            throw NotSupportedException::call('DeepL', 'apply a glossary without the source language');
        }
        $body = array_filter([
            'text' => $query->texts,
            'target_lang' => self::target($query->target),
            'source_lang' => null === $query->source ? null : self::source($query->source),
            'formality' => self::formality($query->formality),
            'tag_handling' => $query->isHtml() ? 'html' : null,
            'glossary_id' => $glossary,
            'model_type' => $this->modelType,
            'show_billed_characters' => true,
        ], static fn ($value) => null !== $value);
        $data = $this->json->request('POST', $this->baseUri.'/v2/translate', ['json' => $body]) ?? throw ProviderException::answered('DeepL', 'POST /v2/translate', 404);
        $answers = $data->list('translations');
        if (\count($answers) !== \count($query->texts)) {
            throw ProviderException::unexpected('DeepL', 'POST /v2/translate', \sprintf('%d translations for %d texts.', \count($answers), \count($query->texts)));
        }

        return array_map(static fn (Data $one) => new Translation(
            \is_string($text = $one->get('text')) ? $text : '',
            $query->target,
            'deepl',
            $query->source,
            null === $query->source ? Language::tryOf($one->string('detected_source_language')) : null,
            $query->format,
            $one->int('billed_characters'),
            $glossary,
        ), $answers);
    }

    public function languages(): array
    {
        $data = $this->json->get($this->baseUri.'/v3/languages', ['resource' => 'translate_text']);
        $languages = [];
        foreach ($data?->items() ?? [] as $one) {
            $language = Language::tryOf($one->string('lang'));
            if (null !== $language) {
                $target = true === $one->bool('usable_as_target');
                $languages[] = new SupportedLanguage($language, $one->string('name'), true === $one->bool('usable_as_source'), $target, $target ? \is_array($one->get('features.formality')) : null);
            }
        }

        return $languages;
    }

    public function createGlossary(string $name, Language $source, Language $target, array $entries): Glossary
    {
        $data = $this->json->request('POST', $this->baseUri.'/v3/glossaries', ['json' => [
            'name' => $name,
            'dictionaries' => [['source_lang' => self::glossaryLanguage($source), 'target_lang' => self::glossaryLanguage($target), 'entries' => Glossary::tsv($entries), 'entries_format' => 'tsv']],
        ]]) ?? throw ProviderException::answered('DeepL', 'POST /v3/glossaries', 404);

        return $this->readGlossary($data);
    }

    public function glossaries(): array
    {
        return array_map($this->readGlossary(...), $this->json->get($this->baseUri.'/v3/glossaries')?->list('glossaries') ?? []);
    }

    public function glossary(string $id): ?Glossary
    {
        $data = $this->json->get($this->baseUri.'/v3/glossaries/'.rawurlencode($id));

        return null === $data ? null : $this->readGlossary($data);
    }

    public function glossaryEntries(string $id, Language $source, Language $target): array
    {
        $data = $this->json->get($this->baseUri.'/v3/glossaries/'.rawurlencode($id).'/entries', ['source_lang' => self::glossaryLanguage($source), 'target_lang' => self::glossaryLanguage($target)]);
        foreach ($data?->list('dictionaries') ?? [] as $dictionary) {
            return Glossary::fromTsv((string) $dictionary->string('entries'));
        }

        return [];
    }

    public function deleteGlossary(string $id): void
    {
        $this->json->binary('DELETE', $this->baseUri.'/v3/glossaries/'.rawurlencode($id));
    }

    public function translateDocument(DocumentQuery $query): DocumentJob
    {
        $glossary = $query->glossaryFor($this->getName());
        if (null !== $glossary && null === $query->source) {
            throw NotSupportedException::call('DeepL', 'apply a glossary without the source language');
        }
        $form = Multipart::encode([
            'target_lang' => self::target($query->target),
            'source_lang' => null === $query->source ? null : self::source($query->source),
            'formality' => self::formality($query->formality),
            'glossary_id' => $glossary,
        ], ['file' => $query->document]);
        $data = $this->json->request('POST', $this->baseUri.'/v2/document', ['body' => $form['body']], $form['headers']) ?? throw ProviderException::answered('DeepL', 'POST /v2/document', 404);
        $id = $data->string('document_id') ?? throw ProviderException::unexpected('DeepL', 'POST /v2/document', 'No document_id.');

        return new DocumentJob($id, 'deepl', DocumentStatus::Queued, $query->target, $query->source, $data->string('document_key'), $query->translatedName());
    }

    public function documentStatus(DocumentJob $job): DocumentJob
    {
        $data = $this->json->request('POST', $this->baseUri.'/v2/document/'.rawurlencode($job->id), ['json' => ['document_key' => (string) $job->key]])
            ?? throw ProviderException::answered('DeepL', 'POST /v2/document/{id}', 404, 'No such document (downloaded already, or its key is wrong).');
        $status = DocumentStatus::tryFrom((string) $data->string('status')) ?? throw ProviderException::unexpected('DeepL', 'POST /v2/document/{id}', \sprintf('Status "%s".', (string) $data->string('status')));

        return $job->with($status, $data->int('seconds_remaining'), $data->int('billed_characters'), $data->string('error_message'));
    }

    public function documentResult(DocumentJob $job): Document
    {
        if (null !== $job->result) {
            return $job->result;
        }
        $file = $this->json->binary('POST', $this->baseUri.'/v2/document/'.rawurlencode($job->id).'/result', ['json' => ['document_key' => (string) $job->key]])
            ?? throw ProviderException::answered('DeepL', 'POST /v2/document/{id}/result', 404, 'No such document (downloaded already, or its key is wrong).');

        return new Document($file['body'], $job->filename ?? $job->id, $file['type']);
    }

    /** A target as DeepL writes it: "EN-GB", "PT-BR", "ZH-HANS". */
    public static function target(Language $language): string
    {
        return strtoupper($language->code);
    }

    /** A source as DeepL takes it: the language alone, "EN". */
    public static function source(Language $language): string
    {
        return strtoupper($language->primary());
    }

    private static function glossaryLanguage(Language $language): string
    {
        return $language->primary();
    }

    private static function formality(?Formality $formality): ?string
    {
        return match ($formality) {
            Formality::Formal => 'prefer_more',
            Formality::Informal => 'prefer_less',
            null => null,
        };
    }

    private function readGlossary(Data $data): Glossary
    {
        $pairs = [];
        foreach ($data->list('dictionaries') as $dictionary) {
            $source = Language::tryOf($dictionary->string('source_lang'));
            $target = Language::tryOf($dictionary->string('target_lang'));
            if (null !== $source && null !== $target) {
                $pairs[] = new LanguagePair($source, $target, $dictionary->int('entry_count'));
            }
        }

        return new Glossary(
            $data->string('glossary_id') ?? throw ProviderException::unexpected('DeepL', 'a glossary', 'No glossary_id.'),
            (string) $data->string('name'),
            'deepl',
            $pairs,
            $data->dateTime('creation_time'),
        );
    }
}
