<?php

declare(strict_types=1);

namespace Itw\Gatecha\Service;

enum VerificationResult: string
{
    case Ok = 'ok';

    case Missing = 'missing';

    case Invalid = 'invalid';

    case Expired = 'expired';

    case BotSuspected = 'bot_suspected';

    case Unavailable = 'unavailable';

    public function violationCode(): string
    {
        return 'captcha.gatecha-' . match ($this) {
            self::Ok, self::Invalid => 'invalid',
            self::Missing => 'missing',
            self::Expired => 'expired',
            self::BotSuspected => 'bot-suspected',
            self::Unavailable => 'unavailable',
        };
    }
}
