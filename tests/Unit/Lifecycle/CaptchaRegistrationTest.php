<?php

declare(strict_types=1);

namespace Itw\Gatecha\Tests\Unit\Lifecycle;

use Doctrine\DBAL\Connection;
use Itw\Gatecha\Lifecycle\CaptchaRegistration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(CaptchaRegistration::class)]
final class CaptchaRegistrationTest extends TestCase
{
    private const SALES_CHANNEL_BYTES = "\x01\x90\xc8\xd2\xa2\xb3\x7c\x0a\x9f\x5e\x1d\x2c\x3b\x4a\x5f\x60";
    private const SALES_CHANNEL_HEX = '0190c8d2a2b37c0a9f5e1d2c3b4a5f60';

    /** @var list<array{string, mixed, ?string}> */
    private array $writes = [];

    public function testRegisterAddsInactiveEntryWhereMissing(): void
    {
        $registration = $this->createRegistration([
            $this->row(null, ['honeypot' => ['name' => 'honeypot', 'isActive' => true]]),
            $this->row(self::SALES_CHANNEL_BYTES, [
                'honeypot' => ['isActive' => false],
                'gatechaCaptcha' => ['name' => 'gatechaCaptcha', 'isActive' => true, 'config' => []],
            ]),
        ]);

        $registration->register();

        static::assertCount(1, $this->writes);
        [$key, $value, $salesChannelId] = $this->writes[0];
        static::assertSame('core.basicInformation.activeCaptchasV2', $key);
        static::assertNull($salesChannelId);
        static::assertSame([
            'honeypot' => ['name' => 'honeypot', 'isActive' => true],
            'gatechaCaptcha' => ['name' => 'gatechaCaptcha', 'isActive' => false, 'config' => []],
        ], $value);
    }

    public function testDeactivateSwitchesOffActiveEntries(): void
    {
        $registration = $this->createRegistration([
            $this->row(null, ['gatechaCaptcha' => ['name' => 'gatechaCaptcha', 'isActive' => false, 'config' => []]]),
            $this->row(self::SALES_CHANNEL_BYTES, [
                'honeypot' => ['isActive' => true],
                'gatechaCaptcha' => ['name' => 'gatechaCaptcha', 'isActive' => true, 'config' => []],
            ]),
        ]);

        $registration->deactivate();

        static::assertCount(1, $this->writes);
        [, $value, $salesChannelId] = $this->writes[0];
        static::assertSame(self::SALES_CHANNEL_HEX, $salesChannelId);
        static::assertFalse($value['gatechaCaptcha']['isActive']);
        static::assertTrue($value['honeypot']['isActive']);
    }

    public function testUnregisterRemovesEntry(): void
    {
        $registration = $this->createRegistration([
            $this->row(null, [
                'honeypot' => ['isActive' => true],
                'gatechaCaptcha' => ['name' => 'gatechaCaptcha', 'isActive' => true, 'config' => []],
            ]),
            $this->row(self::SALES_CHANNEL_BYTES, ['honeypot' => ['isActive' => true]]),
        ]);

        $registration->unregister();

        static::assertCount(1, $this->writes);
        static::assertSame(['honeypot' => ['isActive' => true]], $this->writes[0][1]);
        static::assertNull($this->writes[0][2]);
    }

    public function testSkipsBrokenRows(): void
    {
        $registration = $this->createRegistration([
            ['sales_channel_id' => null, 'configuration_value' => 'not json'],
            ['sales_channel_id' => null, 'configuration_value' => '{"_value":"string"}'],
            ['sales_channel_id' => null, 'configuration_value' => '{}'],
        ]);

        $registration->register();
        $registration->deactivate();
        $registration->unregister();

        static::assertSame([], $this->writes);
    }

    public function testNothingStored(): void
    {
        $this->createRegistration([])->register();

        static::assertSame([], $this->writes);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createRegistration(array $rows): CaptchaRegistration
    {
        $this->writes = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        /** @var SystemConfigService&MockObject $systemConfig */
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('set')->willReturnCallback(
            function (string $key, mixed $value, ?string $salesChannelId = null): void {
                $this->writes[] = [$key, $value, $salesChannelId];
            },
        );

        return new CaptchaRegistration($connection, $systemConfig);
    }

    /**
     * @param array<string, mixed> $captchas
     *
     * @return array{sales_channel_id: ?string, configuration_value: string}
     */
    private function row(?string $salesChannelId, array $captchas): array
    {
        return [
            'sales_channel_id' => $salesChannelId,
            'configuration_value' => json_encode(['_value' => $captchas], \JSON_THROW_ON_ERROR),
        ];
    }
}
