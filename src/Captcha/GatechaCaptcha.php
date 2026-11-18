<?php

declare(strict_types=1);

namespace Itw\Gatecha\Captcha;

use Itw\Gatecha\Config\GatechaConfig;
use Itw\Gatecha\Service\GatechaVerifier;
use Itw\Gatecha\Service\VerificationResult;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Captcha\AbstractCaptcha;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\Service\ResetInterface;

class GatechaCaptcha extends AbstractCaptcha implements ResetInterface
{
    public const CAPTCHA_NAME = 'gatechaCaptcha';
    public const PAYLOAD_PARAMETER = 'altcha';
    public const HIS_PARAMETER = 'gatecha_his_signals';

    private VerificationResult $lastResult = VerificationResult::Ok;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly GatechaVerifier $verifier,
    ) {
    }

    public function isValid(Request $request, array $captchaConfig): bool
    {
        $config = GatechaConfig::load($this->systemConfigService, $this->getSalesChannelId($request));

        $result = $this->verifier->verify(
            $config,
            (string) $request->request->get(self::PAYLOAD_PARAMETER, ''),
            GatechaVerifier::parseHisSignals($request->request->get(self::HIS_PARAMETER)),
            $request->getClientIp(),
        );

        if ($result === VerificationResult::Unavailable && $config->failOpen) {
            $result = VerificationResult::Ok;
        }

        $this->lastResult = $result;

        return $result === VerificationResult::Ok;
    }

    public function getName(): string
    {
        return self::CAPTCHA_NAME;
    }

    public function shouldBreak(): bool
    {
        return false;
    }

    public function getViolations(): ConstraintViolationList
    {
        return new ConstraintViolationList([
            new ConstraintViolation(
                '',
                '',
                [],
                '',
                '/' . self::PAYLOAD_PARAMETER,
                '',
                null,
                $this->lastResult->violationCode(),
            ),
        ]);
    }

    public function reset(): void
    {
        $this->lastResult = VerificationResult::Ok;
    }

    private function getSalesChannelId(Request $request): ?string
    {
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if ($context instanceof SalesChannelContext) {
            return $context->getSalesChannelId();
        }

        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

        return \is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null;
    }
}
