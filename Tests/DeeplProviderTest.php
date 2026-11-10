<?php

namespace Omnilang\Deepl\Tests;

use Omnilang\Deepl\DeeplFactory;
use Omnilang\Deepl\DeeplProvider;
use Omnilang\Exception\InvalidConfigException;
use Omnilang\Exception\NotSupportedException;
use Omnilang\Exception\ProviderException;
use Omnilang\Model\Document;
use Omnilang\Model\DocumentQuery;
use Omnilang\Model\DocumentStatus;
use Omnilang\Model\Formality;
use Omnilang\Model\Language;
use Omnilang\Model\TextFormat;
use Omnilang\Model\TranslationQuery;
use Omnilang\Testing\MockHttp;
use PHPUnit\Framework\TestCase;

/**
 * DeepL's answers written from its OpenAPI description (DeepLcom/openapi,
 * 3.14.0, read on 2026-11-10); no call made (a key is needed). Every test
 * checks the exact request: method, URL, Authorization header, body.
 */
final class DeeplProviderTest extends TestCase
{
    private const KEY = 'not-a-real-deepl-key:fx';
    private const FIXTURES = __DIR__.'/Fixtures/';

    public function testTextsTranslatedTheirSourceDetected(): void
    {
        $http = self::http();
        $translations = self::provider($http)->translate(TranslationQuery::text(['Chambre double avec vue sur le jardin', 'Petit-déjeuner compris'], 'en-gb', formality: Formality::Formal));

        self::assertSame(['Double room with a view of the garden', 'Breakfast included'], array_map('strval', $translations));
        self::assertSame(['en-GB', 'fr', null, 31, 'deepl'], [$translations[0]->target->code, $translations[0]->detected?->code, $translations[0]->source, $translations[0]->characters, $translations[0]->provider]);
        self::assertSame(['POST https://api-free.deepl.com/v2/translate'], $http->lines(), 'a ":fx" key: the Free API');
        self::assertSame('DeepL-Auth-Key '.self::KEY, $http->header('Authorization'));
        self::assertSame(['text' => ['Chambre double avec vue sur le jardin', 'Petit-déjeuner compris'], 'target_lang' => 'EN-GB', 'formality' => 'prefer_more', 'show_billed_characters' => true], $http->json());
    }

    public function testHtmlWithItsSourceAndAGlossary(): void
    {
        $http = self::http();
        $translation = self::provider($http, ['key' => 'not-a-real-pro-key', 'model_type' => 'quality_optimized'])->translate(new TranslationQuery('<p>Réservez <b>maintenant</b></p>', 'ja', 'fr-FR', TextFormat::Html, glossaries: ['deepl' => 'def3a26b']))[0];

        self::assertSame(['<p>ご予約は<b>今すぐ</b></p>', 'fr-FR', null, 'def3a26b'], [$translation->text, $translation->sourceLanguage()?->code, $translation->detected, $translation->glossary]);
        self::assertSame('POST https://api.deepl.com/v2/translate', $http->lines()[0], 'a Pro key: the Pro API');
        self::assertSame(['text' => ['<p>Réservez <b>maintenant</b></p>'], 'target_lang' => 'JA', 'source_lang' => 'FR', 'tag_handling' => 'html', 'glossary_id' => 'def3a26b', 'model_type' => 'quality_optimized', 'show_billed_characters' => true], $http->json());

        $this->expectException(NotSupportedException::class);
        self::provider($http)->translate(new TranslationQuery('Bonjour', 'ja', glossaries: 'def3a26b'));
    }

    public function testTheLanguagesOfTheV3Api(): void
    {
        $http = self::http();
        $languages = self::provider($http)->languages();

        self::assertSame(['de', 'en', 'en-GB', 'ja'], array_map(static fn ($l) => $l->language->code, $languages));
        self::assertSame([true, false, null], [$languages[1]->source, $languages[1]->target, $languages[1]->formality]);
        self::assertSame([false, true, false], [$languages[2]->source, $languages[2]->target, $languages[2]->formality]);
        self::assertTrue($languages[3]->formality);
        self::assertSame(['GET https://api-free.deepl.com/v3/languages?resource=translate_text'], $http->lines());
    }

    public function testAGlossaryMadeListedReadAndDeleted(): void
    {
        $http = self::http();
        $provider = self::provider($http);
        $glossary = $provider->createGlossary('Rooms', Language::of('fr'), Language::of('en-GB'), ["Chambre d'hôtes" => 'Bed and breakfast', "Table d'hôtes" => "Host's table"]);

        self::assertSame(['def3a26b-3e84-45b3-84ae-0c0aaf3525f7', 'Rooms', 2, '2026-11-10T09:16:18+00:00'], [$glossary->id, $glossary->name, $glossary->entries(), $glossary->createdAt?->format(\DATE_ATOM)]);
        self::assertSame(['name' => 'Rooms', 'dictionaries' => [['source_lang' => 'fr', 'target_lang' => 'en', 'entries' => "Chambre d'hôtes\tBed and breakfast\nTable d'hôtes\tHost's table", 'entries_format' => 'tsv']]], $http->json());

        self::assertSame([['fr', 'en', 2], ['fr', 'ja', 2]], array_map(static fn ($p) => [$p->source->code, $p->target->code, $p->entries], $provider->glossaries()[0]->pairs));
        self::assertSame("Bed and breakfast", $provider->glossaryEntries($glossary->id, Language::of('fr'), Language::of('en'))["Chambre d'hôtes"]);
        self::assertNull($provider->glossary('missing'));
        $provider->deleteGlossary($glossary->id);

        self::assertSame([
            'POST https://api-free.deepl.com/v3/glossaries',
            'GET https://api-free.deepl.com/v3/glossaries',
            'GET https://api-free.deepl.com/v3/glossaries/def3a26b-3e84-45b3-84ae-0c0aaf3525f7/entries?source_lang=fr&target_lang=en',
            'GET https://api-free.deepl.com/v3/glossaries/missing',
            'DELETE https://api-free.deepl.com/v3/glossaries/def3a26b-3e84-45b3-84ae-0c0aaf3525f7',
        ], $http->lines());
    }

    public function testADocumentUploadedFollowedAndDownloadedOnce(): void
    {
        $http = self::http();
        $provider = self::provider($http);
        $job = $provider->translateDocument(new DocumentQuery(new Document("PK\x03\x04menu", 'menu.docx'), 'en-US', 'fr', Formality::Informal));

        self::assertSame(['04DE5AD98A02647D83285A36021911C6', DocumentStatus::Queued, 'menu.en-US.docx'], [$job->id, $job->status, $job->filename]);
        self::assertSame('0CB0054F1C132C1625B392EADDA41CB754A742822F6877173029A6C487E7F60A', $job->key);
        $form = $http->form();
        self::assertSame(['target_lang', 'source_lang', 'formality', 'file'], array_keys($form));
        self::assertSame(['EN-US', 'FR', 'prefer_less'], [$form['target_lang']['value'], $form['source_lang']['value'], $form['formality']['value']]);
        self::assertSame(["PK\x03\x04menu", 'menu.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], [$form['file']['value'], $form['file']['filename'], $form['file']['type']]);

        $translating = $provider->documentStatus($job);
        self::assertSame([DocumentStatus::Translating, 20], [$translating->status, $translating->secondsRemaining]);
        $done = $provider->documentStatus($translating);
        self::assertSame([true, 50000, null], [$done->isDone(), $done->billedCharacters, $done->secondsRemaining]);
        self::assertSame(['document_key' => $job->key], $http->json());

        $file = $provider->documentResult($done);
        self::assertSame(["PK\x03\x04menu (en-US)", 'menu.en-US.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], [$file->content, $file->filename, $file->mimeType]);
        self::assertSame('POST https://api-free.deepl.com/v2/document/04DE5AD98A02647D83285A36021911C6/result', $http->lines()[3]);
    }

    public function testErrorsKeepTheKeyOut(): void
    {
        $http = MockHttp::routes(['~/v2/translate~' => [456, '{"message": "Quota exceeded. The character limit has been reached."}']]);
        try {
            self::provider($http)->translate(TranslationQuery::text('Bonjour', 'en-US'));
            self::fail('A 456 is an error.');
        } catch (ProviderException $e) {
            self::assertSame(456, $e->status);
            self::assertSame('DeepL answered 456 for POST /v2/translate. {"message": "Quota exceeded. The character limit has been reached."}', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, serialize([$e->getMessage(), $e->getTraceAsString(), $e->getPrevious()?->getMessage()]));
        }
    }

    public function testAWrongConfigurationIsSaid(): void
    {
        self::assertSame('deepl', (new DeeplFactory())->getName());
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "deepl" provider\'s model_type is one of quality_optimized, prefer_quality_optimized, latency_optimized, not "best".');
        self::provider(self::http(), ['model_type' => 'best']);
    }

    private static function http(): MockHttp
    {
        $calls = 0;

        return MockHttp::routes([
            '~^POST https://api(-free)?\.deepl\.com/v2/translate$~' => static fn (string $method, string $url, string $body): array => [200, '@'.self::FIXTURES.(str_contains($body, 'tag_handling') ? 'translate-html.json' : 'translate.json')],
            '~^GET https://api-free\.deepl\.com/v3/languages\?resource=translate_text$~' => [200, '@'.self::FIXTURES.'languages.json'],
            '~^POST https://api-free\.deepl\.com/v3/glossaries$~' => [201, '@'.self::FIXTURES.'glossary.json'],
            '~^GET https://api-free\.deepl\.com/v3/glossaries$~' => [200, '@'.self::FIXTURES.'glossaries.json'],
            '~^GET https://api-free\.deepl\.com/v3/glossaries/[^/]+/entries\?~' => [200, '@'.self::FIXTURES.'glossary-entries.json'],
            '~^DELETE https://api-free\.deepl\.com/v3/glossaries/~' => [204, ''],
            '~^POST https://api-free\.deepl\.com/v2/document$~' => [200, '@'.self::FIXTURES.'document.json'],
            '~^POST https://api-free\.deepl\.com/v2/document/[0-9A-F]+/result$~' => [200, "PK\x03\x04menu (en-US)", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
            '~^POST https://api-free\.deepl\.com/v2/document/[0-9A-F]+$~' => static function () use (&$calls): array {
                return [200, '@'.self::FIXTURES.(0 === $calls++ ? 'document-translating.json' : 'document-done.json')];
            },
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(MockHttp $http, array $options = []): DeeplProvider
    {
        $provider = (new DeeplFactory($http->client))->create($options + ['key' => self::KEY]);
        self::assertInstanceOf(DeeplProvider::class, $provider);

        return $provider;
    }
}
