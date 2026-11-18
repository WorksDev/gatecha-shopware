<?php

declare(strict_types=1);

namespace Itw\Gatecha\Tests\Unit\Config;

use Itw\Gatecha\Config\GatechaConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

#[CoversClass(GatechaConfig::class)]
final class GatechaConfigTest extends TestCase
{
    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $config = GatechaConfig::load(new StaticSystemConfigService(), null);

        static::assertSame('', $config->url);
        static::assertSame('', $config->apiKey);
        static::assertTrue($config->his);
        static::assertFalse($config->failOpen);
        static::assertSame(5, $config->timeout);
        static::assertFalse($config->isConfigured());
    }

    public function testNormalisesValues(): void
    {
        $config = GatechaConfig::load(new StaticSystemConfigService([
            'ItwGatecha.config.url' => '  https://gatecha.example.com/  ',
            'ItwGatecha.config.apiKey' => ' gk_abc ',
            'ItwGatecha.config.his' => false,
            'ItwGatecha.config.failOpen' => true,
            'ItwGatecha.config.timeout' => '7',
        ]), null);

        static::assertSame('https://gatecha.example.com', $config->url);
        static::assertSame('gk_abc', $config->apiKey);
        static::assertFalse($config->his);
        static::assertTrue($config->failOpen);
        static::assertSame(7, $config->timeout);
        static::assertTrue($config->isConfigured());
    }

    #[DataProvider('timeoutProvider')]
    public function testTimeout(mixed $value, int $expected): void
    {
        $config = GatechaConfig::load(new StaticSystemConfigService(['ItwGatecha.config.timeout' => $value]), null);

        static::assertSame($expected, $config->timeout);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function timeoutProvider(): iterable
    {
        yield 'zero is raised to one' => [0, 1];
        yield 'negative is raised to one' => [-4, 1];
        yield 'not numeric falls back' => ['soon', 5];
        yield 'null falls back' => [null, 5];
        yield 'int' => [12, 12];
    }

    #[DataProvider('incompleteProvider')]
    public function testIsConfiguredNeedsUrlAndKey(string $url, string $apiKey): void
    {
        $config = GatechaConfig::load(new StaticSystemConfigService([
            'ItwGatecha.config.url' => $url,
            'ItwGatecha.config.apiKey' => $apiKey,
        ]), null);

        static::assertFalse($config->isConfigured());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function incompleteProvider(): iterable
    {
        yield 'no url' => ['', 'gk_abc'];
        yield 'no key' => ['https://gatecha.example.com', ''];
        yield 'only slash' => ['/', 'gk_abc'];
        yield 'blank key' => ['https://gatecha.example.com', '   '];
    }

    public function testVerifyUrlEncodesKey(): void
    {
        $config = GatechaConfig::load(new StaticSystemConfigService([
            'ItwGatecha.config.url' => 'https://gatecha.example.com/sub/',
            'ItwGatecha.config.apiKey' => 'gk_a&b=c',
        ]), null);

        static::assertSame('https://gatecha.example.com/sub/api/v1/verify?apiKey=gk_a%26b%3Dc', $config->verifyUrl());
    }

    public function testReadsSalesChannelValues(): void
    {
        $systemConfig = new StaticSystemConfigService([
            'ItwGatecha.config.url' => 'https://gatecha.example.com',
            'ItwGatecha.config.apiKey' => 'gk_default',
            'sales-channel-b2b' => [
                'ItwGatecha.config.url' => 'https://gatecha.example.com',
                'ItwGatecha.config.apiKey' => 'gk_b2b',
            ],
        ]);

        static::assertSame('gk_default', GatechaConfig::load($systemConfig, null)->apiKey);
        static::assertSame('gk_b2b', GatechaConfig::load($systemConfig, 'sales-channel-b2b')->apiKey);
    }
}
