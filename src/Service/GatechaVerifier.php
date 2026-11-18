<?php

declare(strict_types=1);

namespace Itw\Gatecha\Service;

use GuzzleHttp\ClientInterface;
use Itw\Gatecha\Config\GatechaConfig;
use Psr\Log\LoggerInterface;

class GatechaVerifier
{
    private const MAX_PAYLOAD_LENGTH = 8192;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, int|float>|null $hisSignals
     */
    public function verify(
        GatechaConfig $config,
        string $payload,
        ?array $hisSignals = null,
        ?string $clientIp = null,
    ): VerificationResult {
        if (!$config->isConfigured()) {
            $this->logger->error('GateCHA is not configured: set the server URL and the API key in the plugin configuration.');

            return VerificationResult::Unavailable;
        }

        $payload = \trim($payload);
        if ($payload === '') {
            return VerificationResult::Missing;
        }
        if (\strlen($payload) > self::MAX_PAYLOAD_LENGTH) {
            return VerificationResult::Invalid;
        }

        $body = ['payload' => $payload];
        if ($config->his && $hisSignals !== null && $hisSignals !== []) {
            $body['his_signals'] = $hisSignals;
        }

        $headers = ['Accept' => 'application/json'];
        if ($clientIp !== null && $clientIp !== '') {
            $headers['X-Forwarded-For'] = $clientIp;
        }

        try {
            $response = $this->httpClient->request('POST', $config->verifyUrl(), [
                'headers' => $headers,
                'json' => $body,
                'timeout' => $config->timeout,
                'connect_timeout' => $config->timeout,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('GateCHA verify request failed.', ['exception' => $e]);

            return VerificationResult::Unavailable;
        }

        $status = $response->getStatusCode();
        $data = \json_decode((string) $response->getBody(), true);

        if (!\is_array($data) || !\is_bool($data['ok'] ?? null)) {
            $this->logger->error('GateCHA returned an unexpected response.', [
                'status' => $status,
                'error' => \is_array($data) ? ($data['error'] ?? null) : null,
            ]);

            return VerificationResult::Unavailable;
        }

        if ($data['ok'] === true) {
            return VerificationResult::Ok;
        }

        $error = (string) ($data['error'] ?? '');
        if ($status >= 500) {
            $this->logger->error('GateCHA could not verify the submission.', ['status' => $status, 'error' => $error]);

            return VerificationResult::Unavailable;
        }

        $this->logger->info('GateCHA rejected a submission.', [
            'error' => $error,
            'his_bot_score' => $data['his_bot_score'] ?? null,
        ]);

        return match ($error) {
            'bot_suspected' => VerificationResult::BotSuspected,
            'expired', 'challenge_expired' => VerificationResult::Expired,
            'missing payload' => VerificationResult::Missing,
            default => VerificationResult::Invalid,
        };
    }

    /**
     * @return array<string, int|float>|null
     */
    public static function parseHisSignals(mixed $raw): ?array
    {
        if (!\is_string($raw) || $raw === '' || \strlen($raw) > 4096) {
            return null;
        }

        $signals = \json_decode($raw, true);
        if (!\is_array($signals)) {
            return null;
        }

        $signals = \array_filter(
            $signals,
            static fn (mixed $value, mixed $key): bool => \is_string($key) && (\is_int($value) || \is_float($value)),
            \ARRAY_FILTER_USE_BOTH,
        );

        return $signals === [] ? null : $signals;
    }
}
