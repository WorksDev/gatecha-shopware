<?php

declare(strict_types=1);

namespace Itw\Gatecha\Tests\Unit\Captcha;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Itw\Gatecha\Captcha\GatechaCaptcha;
use Itw\Gatecha\Service\GatechaVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(GatechaCaptcha::class)]
final class GatechaCaptchaTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0190c8d2a2b37c0a9f5e1d2c3b4a5f60';

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function testName(): void
    {
        static::assertSame('gatechaCaptcha', $this->createCaptcha([])->getName());
    }

    public function testDoesNotBreak(): void
    {
        static::assertFalse($this->createCaptcha([])->shouldBreak());
    }

    public function testSupportsOnlyActivePostRequests(): void
    {
        $captcha = $this->createCaptcha([]);

        static::assertTrue($captcha->supports(Request::create('/form', 'POST'), ['isActive' => true]));
        static::assertFalse($captcha->supports(Request::create('/form', 'POST'), ['isActive' => false]));
        static::assertFalse($captcha->supports(Request::create('/form', 'POST'), []));
        static::assertFalse($captcha->supports(Request::create('/form', 'GET'), ['isActive' => true]));
    }

    public function testValidSubmission(): void
    {
        $captcha = $this->createCaptcha([new Response(200, [], '{"ok":true}')]);

        $request = $this->createRequest([
            'altcha' => 'cGF5bG9hZA==',
            'gatecha_his_signals' => '{"keys":5,"ua":"ignored"}',
        ]);

        static::assertTrue($captcha->isValid($request, ['isActive' => true]));

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        static::assertSame(['payload' => 'cGF5bG9hZA==', 'his_signals' => ['keys' => 5]], $body);
        static::assertSame('198.51.100.23', $this->history[0]['request']->getHeaderLine('X-Forwarded-For'));
    }

    public function testUsesKeyOfTheSalesChannelFromContext(): void
    {
        $captcha = $this->createCaptcha([new Response(200, [], '{"ok":true}')]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $request = $this->createRequest(['altcha' => 'cGF5bG9hZA==']);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $context);

        $captcha->isValid($request, ['isActive' => true]);

        static::assertStringEndsWith('apiKey=gk_channel', (string) $this->history[0]['request']->getUri());
    }

    public function testUsesKeyOfTheSalesChannelFromAttribute(): void
    {
        $captcha = $this->createCaptcha([new Response(200, [], '{"ok":true}')]);

        $request = $this->createRequest(['altcha' => 'cGF5bG9hZA==']);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, self::SALES_CHANNEL_ID);

        $captcha->isValid($request, ['isActive' => true]);

        static::assertStringEndsWith('apiKey=gk_channel', (string) $this->history[0]['request']->getUri());
    }

    public function testRejectedSubmissionReportsViolation(): void
    {
        $captcha = $this->createCaptcha([new Response(200, [], '{"ok":false,"error":"bot_suspected"}')]);

        static::assertFalse($captcha->isValid($this->createRequest(['altcha' => 'cGF5bG9hZA==']), ['isActive' => true]));

        $violations = $captcha->getViolations();
        static::assertCount(1, $violations);
        static::assertSame('captcha.gatecha-bot-suspected', $violations->get(0)->getCode());
        static::assertSame('/altcha', $violations->get(0)->getPropertyPath());
    }

    public function testMissingPayload(): void
    {
        $captcha = $this->createCaptcha([]);

        static::assertFalse($captcha->isValid($this->createRequest([]), ['isActive' => true]));
        static::assertSame('captcha.gatecha-missing', $captcha->getViolations()->get(0)->getCode());
        static::assertSame([], $this->history);
    }

    public function testFailOpenAcceptsWhenGatechaIsDown(): void
    {
        $captcha = $this->createCaptcha(
            [new ConnectException('down', new Psr7Request('POST', 'https://gatecha.example.com'))],
            ['ItwGatecha.config.failOpen' => true],
        );

        static::assertTrue($captcha->isValid($this->createRequest(['altcha' => 'cGF5bG9hZA==']), ['isActive' => true]));
    }

    public function testFailClosedRejectsWhenGatechaIsDown(): void
    {
        $captcha = $this->createCaptcha([
            new ConnectException('down', new Psr7Request('POST', 'https://gatecha.example.com')),
        ]);

        static::assertFalse($captcha->isValid($this->createRequest(['altcha' => 'cGF5bG9hZA==']), ['isActive' => true]));
        static::assertSame('captcha.gatecha-unavailable', $captcha->getViolations()->get(0)->getCode());
    }

    public function testFailOpenDoesNotAcceptInvalidSolutions(): void
    {
        $captcha = $this->createCaptcha(
            [new Response(200, [], '{"ok":false,"error":"invalid_solution"}')],
            ['ItwGatecha.config.failOpen' => true],
        );

        static::assertFalse($captcha->isValid($this->createRequest(['altcha' => 'cGF5bG9hZA==']), ['isActive' => true]));
    }

    public function testFailOpenAcceptsWhenNotConfigured(): void
    {
        $captcha = $this->createCaptcha([], ['ItwGatecha.config.apiKey' => '', 'ItwGatecha.config.failOpen' => true]);

        static::assertTrue($captcha->isValid($this->createRequest(['altcha' => 'cGF5bG9hZA==']), ['isActive' => true]));
        static::assertSame([], $this->history);
    }

    public function testResetClearsLastResult(): void
    {
        $captcha = $this->createCaptcha([new Response(200, [], '{"ok":false,"error":"expired"}')]);
        $captcha->isValid($this->createRequest(['altcha' => 'cGF5bG9hZA==']), ['isActive' => true]);
        static::assertSame('captcha.gatecha-expired', $captcha->getViolations()->get(0)->getCode());

        $captcha->reset();

        static::assertSame('captcha.gatecha-invalid', $captcha->getViolations()->get(0)->getCode());
    }

    /**
     * @param list<ResponseInterface|\Throwable> $responses
     * @param array<string, mixed> $config
     */
    private function createCaptcha(array $responses, array $config = []): GatechaCaptcha
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $global = array_merge([
            'ItwGatecha.config.url' => 'https://gatecha.example.com',
            'ItwGatecha.config.apiKey' => 'gk_default',
            'ItwGatecha.config.his' => true,
            'ItwGatecha.config.failOpen' => false,
            'ItwGatecha.config.timeout' => 5,
        ], $config);

        $systemConfig = new StaticSystemConfigService($global + [
            self::SALES_CHANNEL_ID => array_merge($global, ['ItwGatecha.config.apiKey' => 'gk_channel']),
        ]);

        return new GatechaCaptcha(
            $systemConfig,
            new GatechaVerifier(new Client(['handler' => $stack]), new NullLogger()),
        );
    }

    /**
     * @param array<string, string> $post
     */
    private function createRequest(array $post): Request
    {
        return Request::create('/form/contact', 'POST', $post, [], [], ['REMOTE_ADDR' => '198.51.100.23']);
    }
}
