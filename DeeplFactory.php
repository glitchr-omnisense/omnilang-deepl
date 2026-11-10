<?php

namespace Omnilang\Deepl;

use Omnilang\Config;
use Omnilang\Exception\InvalidConfigException;
use Omnilang\Http\JsonClient;
use Omnilang\ProviderFactory;
use Omnilang\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "deepl": options key (required, by reference: "env:DEEPL_API_KEY", or the
 * value the application reads from its vault - never written in a
 * configuration file), base_uri (https://api-free.deepl.com for a key
 * ending in ":fx", https://api.deepl.com otherwise), model_type
 * (quality_optimized, prefer_quality_optimized, latency_optimized; DeepL's
 * default when not given), timeout (seconds); policy (the site's
 * tightening of DeepL's usage policy: its plan's quota), usage.
 */
final class DeeplFactory extends ProviderFactory
{
    private const MODELS = ['quality_optimized', 'prefer_quality_optimized', 'latency_optimized'];

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilang.factory_name' => 'deepl',
            'omnilang.factory_title' => 'DeepL API',
            'omnilang.required_options' => ['key'],
            'base_uri' => null,
            'model_type' => null,
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $key = (string) $c->secret('key');
        $base = rtrim($c->string('base_uri') ?? (str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com'), '/');
        if (!preg_match('~^https?://[^/\s]+~', $base)) {
            throw new InvalidConfigException(\sprintf('The "deepl" provider needs a base_uri such as https://api.deepl.com, not "%s".', $base));
        }
        $model = $c->string('model_type');
        if (null !== $model && !\in_array($model, self::MODELS, true)) {
            throw new InvalidConfigException(\sprintf('The "deepl" provider\'s model_type is one of %s, not "%s".', implode(', ', self::MODELS), $model));
        }
        $http = $this->http ?? HttpClient::create(['timeout' => 60]);
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }

        return new DeeplProvider(new JsonClient($http, 'DeepL', ['Authorization' => 'DeepL-Auth-Key '.$key], $c->backoff(), [$key]), $base, $model);
    }
}
