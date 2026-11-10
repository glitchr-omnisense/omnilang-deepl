<?php

namespace Omnilang\Deepl\Tests;

use Omnilang\Deepl\DeeplProvider;
use Omnilang\Testing\BareTestCase;

/** omnilang/deepl's classes load with omnisense/omnilang, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return DeeplProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
