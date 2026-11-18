<?php

declare(strict_types=1);

namespace Itw\Gatecha;

use Doctrine\DBAL\Connection;
use Itw\Gatecha\Lifecycle\CaptchaRegistration;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ItwGatecha extends Plugin
{
    public function postInstall(InstallContext $installContext): void
    {
        $this->captchaRegistration()->register();
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        $this->captchaRegistration()->register();
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->captchaRegistration()->deactivate();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->captchaRegistration()->unregister();
    }

    private function captchaRegistration(): CaptchaRegistration
    {
        \assert($this->container !== null);

        return new CaptchaRegistration(
            $this->container->get(Connection::class),
            $this->container->get(SystemConfigService::class),
        );
    }
}
