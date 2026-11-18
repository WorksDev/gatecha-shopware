<?php

declare(strict_types=1);

namespace Itw\Gatecha\Lifecycle;

use Doctrine\DBAL\Connection;
use Itw\Gatecha\Captcha\GatechaCaptcha;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class CaptchaRegistration
{
    public const CONFIG_KEY = 'core.basicInformation.activeCaptchasV2';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function register(): void
    {
        $this->modify(static function (array $captchas): ?array {
            if (isset($captchas[GatechaCaptcha::CAPTCHA_NAME])) {
                return null;
            }

            $captchas[GatechaCaptcha::CAPTCHA_NAME] = [
                'name' => GatechaCaptcha::CAPTCHA_NAME,
                'isActive' => false,
                'config' => [],
            ];

            return $captchas;
        });
    }

    public function deactivate(): void
    {
        $this->modify(static function (array $captchas): ?array {
            if (!\is_array($captchas[GatechaCaptcha::CAPTCHA_NAME] ?? null)
                || ($captchas[GatechaCaptcha::CAPTCHA_NAME]['isActive'] ?? false) === false
            ) {
                return null;
            }

            $captchas[GatechaCaptcha::CAPTCHA_NAME]['isActive'] = false;

            return $captchas;
        });
    }

    public function unregister(): void
    {
        $this->modify(static function (array $captchas): ?array {
            if (!\array_key_exists(GatechaCaptcha::CAPTCHA_NAME, $captchas)) {
                return null;
            }

            unset($captchas[GatechaCaptcha::CAPTCHA_NAME]);

            return $captchas;
        });
    }

    /**
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $change returns null for "no change"
     */
    private function modify(callable $change): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT `sales_channel_id`, `configuration_value` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => self::CONFIG_KEY],
        );

        foreach ($rows as $row) {
            $stored = \json_decode((string) $row['configuration_value'], true);
            $captchas = \is_array($stored) && \is_array($stored['_value'] ?? null) ? $stored['_value'] : null;
            if ($captchas === null) {
                continue;
            }

            $changed = $change($captchas);
            if ($changed === null) {
                continue;
            }

            $salesChannelId = $row['sales_channel_id'] !== null
                ? Uuid::fromBytesToHex((string) $row['sales_channel_id'])
                : null;

            $this->systemConfigService->set(self::CONFIG_KEY, $changed, $salesChannelId);
        }
    }
}
