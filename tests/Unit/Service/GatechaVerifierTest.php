<?php

declare(strict_types=1);

namespace Itw\Gatecha\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Itw\Gatecha\Config\GatechaConfig;
use Itw\Gatecha\Service\GatechaVerifier;
use Itw\Gatecha\Service\VerificationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

#[CoversClass(GatechaVerifier::class)]
final class GatechaVerifierTest extends TestCase
{
    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function testAcceptedSolution(): void
    {
        $verifier = $this->createVerifier([new Response(200, [], '{"ok":true}')]);

        static::assertSame(VerificationResult::Ok, $verifier->verify($this->config(), 'cGF5bG9hZA=='));
    }

    public function testSendsPayloadToVerifyEndpoint(): void
    {
        $verifier = $this->createVerifier([new Response(200, [], '{"ok":true}')]);

        $verifier->verify($this->config(['timeout' => 3]), ' cGF5bG9hZA== ', ['keys' => 4], '203.0.113.7');

        static::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        static::assertSame('POST', $request->getMethod());
        static::assertSame('https://gatecha.example.com/api/v1/verify?apiKey=gk_test', (string) $request->getUri());
        static::assertSame('application/json', $request->getHeaderLine('Accept'));
        static::assertSame('203.0.113.7', $request->getHeaderLine('X-Forwarded-For'));
        static::assertSame(
            ['payload' => 'cGF5bG9hZA==', 'his_signals' => ['keys' => 4]],
            json_decode((string) $request->getBody(), true),
        );

        $options = $this->history[0]['options'];
        static::assertSame(3, $options['timeout']);
        static::assertSame(3, $options['connect_timeout']);
        static::assertFalse($options['allow_redirects']);
    }

    public function testOmitsHisSignalsWhenDisabled(): void
    {
        $verifier = $this->createVerifier([new Response(200, [], '{"ok":true}')]);

        $verifier->verify($this->config(['his' => false]), 'cGF5bG9hZA==', ['keys' => 4]);

        static::assertArrayNotHasKey('his_signals', $this->sentBody());
    }

    public function testOmitsEmptyHisSignalsAndClientIp(): void
    {
        $verifier = $this->createVerifier([new Response(200, [], '{"ok":true}')]);

        $verifier->verify($this->config(), 'cGF5bG9hZA==', [], '');

        static::assertArrayNotHasKey('his_signals', $this->sentBody());
        static::assertFalse($this->history[0]['request']->hasHeader('X-Forwarded-For'));
    }

    public function testMissingPayloadDoesNotCallGatecha(): void
    {
        $verifier = $this->createVerifier([]);

        static::assertSame(VerificationResult::Missing, $verifier->verify($this->config(), '   '));
        static::assertSame([], $this->history);
    }

    public function testOversizedPayloadDoesNotCallGatecha(): void
    {
        $verifier = $this->createVerifier([]);

        static::assertSame(VerificationResult::Invalid, $verifier->verify($this->config(), str_repeat('a', 8193)));
        static::assertSame([], $this->history);
    }

    public function testUnconfiguredIsUnavailableAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');
        $verifier = $this->createVerifier([], $logger);

        static::assertSame(VerificationResult::Unavailable, $verifier->verify($this->config(['apiKey' => '']), 'cGF5bG9hZA=='));
        static::assertSame([], $this->history);
    }

    #[DataProvider('rejectionProvider')]
    public function testMapsRejections(ResponseInterface $response, VerificationResult $expected): void
    {
        $verifier = $this->createVerifier([$response]);

        static::assertSame($expected, $verifier->verify($this->config(), 'cGF5bG9hZA=='));
    }

    /**
     * @return iterable<string, array{ResponseInterface, VerificationResult}>
     */
    public static function rejectionProvider(): iterable
    {
        yield 'invalid solution' => [new Response(200, [], '{"ok":false,"error":"invalid_solution"}'), VerificationResult::Invalid];
        yield 'replay' => [new Response(200, [], '{"ok":false,"error":"already_used"}'), VerificationResult::Invalid];
        yield 'bad encoding' => [new Response(200, [], '{"ok":false,"error":"invalid payload encoding"}'), VerificationResult::Invalid];
        yield 'verification failed' => [new Response(200, [], '{"ok":false,"error":"verification failed"}'), VerificationResult::Invalid];
        yield 'expired' => [new Response(200, [], '{"ok":false,"error":"expired"}'), VerificationResult::Expired];
        yield 'challenge expired' => [new Response(200, [], '{"ok":false,"error":"challenge_expired"}'), VerificationResult::Expired];
        yield 'bot suspected' => [new Response(200, [], '{"ok":false,"error":"bot_suspected","his_bot_score":0.93,"his_bot_suspected":true}'), VerificationResult::BotSuspected];
        yield 'missing payload' => [new Response(400, [], '{"ok":false,"error":"missing payload"}'), VerificationResult::Missing];
    }

    #[DataProvider('unavailableProvider')]
    public function testUnexpectedResponsesAreUnavailable(ResponseInterface $response): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error');
        $verifier = $this->createVerifier([$response], $logger);

        static::assertSame(VerificationResult::Unavailable, $verifier->verify($this->config(), 'cGF5bG9hZA=='));
    }

    /**
     * @return iterable<string, array{ResponseInterface}>
     */
    public static function unavailableProvider(): iterable
    {
        yield 'unknown key' => [new Response(401, [], '{"error":"invalid API key"}')];
        yield 'domain restriction' => [new Response(403, [], '{"error":"domain not allowed"}')];
        yield 'rate limit' => [new Response(429, [], '{"error":"rate limit exceeded"}')];
        yield 'server error with ok' => [new Response(500, [], '{"ok":false,"error":"internal error"}')];
        yield 'html from proxy' => [new Response(502, [], '<html>Bad Gateway</html>')];
        yield 'ok is not a bool' => [new Response(200, [], '{"ok":"true"}')];
        yield 'empty body' => [new Response(200)];
    }

    public function testConnectionErrorIsUnavailable(): void
    {
        $verifier = $this->createVerifier([
            new ConnectException('Connection timed out', new Request('POST', 'https://gatecha.example.com')),
        ]);

        static::assertSame(VerificationResult::Unavailable, $verifier->verify($this->config(), 'cGF5bG9hZA=='));
    }

    /**
     * @param array<string, int|float>|null $expected
     */
    #[DataProvider('hisSignalsProvider')]
    public function testParseHisSignals(mixed $raw, ?array $expected): void
    {
        static::assertSame($expected, GatechaVerifier::parseHisSignals($raw));
    }

    /**
     * @return iterable<string, array{mixed, array<string, int|float>|null}>
     */
    public static function hisSignalsProvider(): iterable
    {
        yield 'numbers only' => ['{"keys":3,"moves":1.5}', ['keys' => 3, 'moves' => 1.5]];
        yield 'non numeric values dropped' => ['{"keys":3,"ua":"x","flag":true,"list":[1]}', ['keys' => 3]];
        yield 'list keys dropped' => ['[1,2,3]', null];
        yield 'nothing usable' => ['{"ua":"x"}', null];
        yield 'invalid json' => ['{keys:3', null];
        yield 'empty string' => ['', null];
        yield 'not a string' => [['keys' => 3], null];
        yield 'null' => [null, null];
        yield 'too long' => ['{"a":1,"pad":"' . str_repeat('x', 4100) . '"}', null];
    }

    /**
     * @param list<ResponseInterface|\Throwable> $responses
     */
    private function createVerifier(array $responses, ?LoggerInterface $logger = null): GatechaVerifier
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GatechaVerifier(new Client(['handler' => $stack]), $logger ?? new NullLogger());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function config(array $overrides = []): GatechaConfig
    {
        $values = [
            'url' => 'https://gatecha.example.com',
            'apiKey' => 'gk_test',
            'his' => true,
            'failOpen' => false,
            'timeout' => 5,
        ];

        $config = [];
        foreach (array_merge($values, $overrides) as $key => $value) {
            $config[GatechaConfig::PREFIX . $key] = $value;
        }

        return GatechaConfig::load(new StaticSystemConfigService($config), null);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        static::assertIsArray($body);

        return $body;
    }
}
