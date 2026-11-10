<?php

namespace Omnilang\Deepl\Tests;

use Omnilang\Deepl\DeeplFactory;
use Omnilang\Exception\UsageLimitReachedException;
use Omnilang\Http\Data;
use Omnilang\Model\TranslationQuery;
use Omnilang\Registry;
use Omnilang\Testing\MockHttp;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** DeepL's policy as declared in policy.json, a site's quota on top of it, DeepL's own 429 honoured. */
final class PolicyTest extends TestCase
{
    public function testTheDeclaredPolicy(): void
    {
        $policy = self::registry(MockHttp::routes([]))->policy('deepl');
        $composer = Data::of(json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame('policy.json', $composer->string('extra.omnisense.policy'));
        self::assertEquals(UsagePolicy::fromFile(__DIR__.'/../policy.json'), $policy);
        self::assertNull($policy->rateLimits, 'no request rate published');
        self::assertSame([Permission::Allowed, true, 'DeepL (deepl.com)', true, '2026-11-10'], [$policy->rights->commercial, $policy->rights->attribution->required, $policy->rights->attribution->text, $policy->requirements->key, $policy->provenance->verifiedAt]);
        self::assertStringContainsString('72 hours', (string) $policy->provenance->notes);
    }

    public function testASitesQuotaAndDeeplsOwnWait(): void
    {
        $calls = 0;
        $http = MockHttp::routes(['~/v2/translate~' => static function () use (&$calls): array {
            return 0 === $calls++ ? [200, '{"translations": [{"detected_source_language": "FR", "text": "Hello"}]}'] : [429, '{"message": "Too many requests"}', ['Retry-After' => '12']];
        }]);
        $registry = self::registry($http, ['rateLimits' => [['max' => 3, 'window' => 'day', 'operations' => ['translate']]]]);

        self::assertSame('Hello', $registry->translator('deepl')->translate(TranslationQuery::text('Bonjour', 'en-US'))[0]->text);
        try {
            $registry->translator('deepl')->translate(TranslationQuery::text('Bonjour', 'en-US'));
            self::fail('DeepL asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(12, $e->retryAfter);
        }
        $this->expectException(UsageLimitReachedException::class);
        $registry->translator('deepl')->translate(TranslationQuery::text('Bonjour', 'en-US'));
    }

    /** @param array<string, mixed> $policy */
    private static function registry(MockHttp $http, array $policy = []): Registry
    {
        return new Registry([new DeeplFactory($http->client)], ['deepl' => ['factory' => 'deepl', 'options' => ['key' => 'not-a-real-key:fx'], 'policy' => $policy]]);
    }
}
