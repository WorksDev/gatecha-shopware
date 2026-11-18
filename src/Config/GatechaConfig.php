<?php

declare(strict_types=1);

namespace Itw\Gatecha\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class GatechaConfig
{
    public const PREFIX = 'ItwGatecha.config.';

    private function __construct(
        public readonly string $url,
        public readonly string $apiKey,
        public readonly bool $his,
        public readonly bool $failOpen,
        public readonly int $timeout,
    ) {
    }

    public static function load(SystemConfigService $systemConfigService, ?string $salesChannelId): self
    {
        $get = static fn (string $key): mixed => $systemConfigService->get(self::PREFIX . $key, $salesChannelId);

        $timeout = $get('timeout');

        return new self(
            url: \rtrim(\trim((string) $get('url')), '/'),
            apiKey: \trim((string) $get('apiKey')),
            his: (bool) ($get('his') ?? true),
            failOpen: (bool) ($get('failOpen') ?? false),
            timeout: \is_numeric($timeout) ? \max(1, (int) $timeout) : 5,
        );
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->apiKey !== '';
    }

    public function verifyUrl(): string
    {
        return $this->url . '/api/v1/verify?apiKey=' . \rawurlencode($this->apiKey);
    }
}
