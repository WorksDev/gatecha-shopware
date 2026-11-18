<?php

declare(strict_types=1);

namespace Itw\Gatecha\Tests\Unit\Service;

use Itw\Gatecha\Service\VerificationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerificationResult::class)]
final class VerificationResultTest extends TestCase
{
    private const SNIPPET_FILES = [
        'de-DE' => __DIR__ . '/../../../src/Resources/snippet/de_DE/gatecha.de-DE.json',
        'en-GB' => __DIR__ . '/../../../src/Resources/snippet/en_GB/gatecha.en-GB.json',
    ];

    public function testViolationCodes(): void
    {
        static::assertSame('captcha.gatecha-invalid', VerificationResult::Invalid->violationCode());
        static::assertSame('captcha.gatecha-invalid', VerificationResult::Ok->violationCode());
        static::assertSame('captcha.gatecha-missing', VerificationResult::Missing->violationCode());
        static::assertSame('captcha.gatecha-expired', VerificationResult::Expired->violationCode());
        static::assertSame('captcha.gatecha-bot-suspected', VerificationResult::BotSuspected->violationCode());
        static::assertSame('captcha.gatecha-unavailable', VerificationResult::Unavailable->violationCode());
    }

    #[DataProvider('localeProvider')]
    public function testEveryViolationHasAStorefrontSnippet(string $file): void
    {
        $snippets = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        static::assertIsArray($snippets);

        foreach (VerificationResult::cases() as $result) {
            // Shopware renders 'error.' . code, e.g. error.captcha.gatecha-invalid
            $value = $snippets;
            foreach (explode('.', 'error.' . $result->violationCode()) as $part) {
                static::assertIsArray($value);
                static::assertArrayHasKey($part, $value, \sprintf('Missing snippet error.%s in %s', $result->violationCode(), basename($file)));
                $value = $value[$part];
            }

            static::assertIsString($value);
            static::assertNotSame('', trim($value));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        foreach (self::SNIPPET_FILES as $locale => $file) {
            yield $locale => [$file];
        }
    }
}
