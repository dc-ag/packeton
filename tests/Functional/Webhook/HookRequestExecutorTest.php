<?php

declare(strict_types=1);

namespace Packeton\Tests\Functional\Webhook;

use Packeton\Entity\Webhook;
use Packeton\Webhook\HmacOpensslCrypter;
use Packeton\Webhook\HookRequest;
use Packeton\Webhook\HookRequestExecutor;
use Packeton\Webhook\RequestResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HookRequestExecutorTest extends TestCase
{
    private array $plainCalls = [];
    private array $noPrivateCalls = [];

    public function testAllowedPrivateHostUsesThePlainClientWithoutRedirects(): void
    {
        $executor = $this->executor('http://renovate-controller-svc.renovate.svc.cluster.local/packageUpdated', ['renovate-controller-svc.renovate.svc.cluster.local']);

        $executor->executeWebhook(new Webhook());

        self::assertCount(1, $this->plainCalls);
        self::assertSame([], $this->noPrivateCalls);
        self::assertSame(0, $this->plainCalls[0]['max_redirects']);
    }

    public function testHostMatchIgnoresCaseAndSurroundingSpaces(): void
    {
        $executor = $this->executor('http://Renovate.Example.Test/packageUpdated', [' renovate.example.test ', '']);

        $executor->executeWebhook(new Webhook());

        self::assertCount(1, $this->plainCalls);
    }

    public function testOtherHostsStayOnTheNoPrivateNetworkClient(): void
    {
        $executor = $this->executor('http://10.0.0.1/packageUpdated', ['renovate.example.test']);

        $executor->executeWebhook(new Webhook());

        self::assertSame([], $this->plainCalls);
        self::assertCount(1, $this->noPrivateCalls);
    }

    public function testWithoutAllowedHostsEveryRequestUsesTheNoPrivateNetworkClient(): void
    {
        $executor = $this->executor('http://renovate.example.test/packageUpdated', []);

        $executor->executeWebhook(new Webhook());

        self::assertSame([], $this->plainCalls);
        self::assertCount(1, $this->noPrivateCalls);
    }

    public function testAnExplicitClientWins(): void
    {
        $executor = $this->executor('http://renovate.example.test/packageUpdated', ['renovate.example.test']);
        $explicitCalls = 0;

        $executor->executeWebhook(new Webhook(), [], new MockHttpClient(function () use (&$explicitCalls) {
            $explicitCalls++;

            return new MockResponse('');
        }));

        self::assertSame(1, $explicitCalls);
        self::assertSame([], $this->plainCalls);
        self::assertSame([], $this->noPrivateCalls);
    }

    private function executor(string $url, array $privateHosts): HookRequestExecutor
    {
        $resolver = $this->createMock(RequestResolver::class);
        $resolver->method('resolveHook')->willReturn([new HookRequest($url, 'POST')]);

        $plain = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->plainCalls[] = $options;

            return new MockResponse('');
        });
        $noPrivate = new MockHttpClient(function (string $method, string $url, array $options) {
            $this->noPrivateCalls[] = $options;

            return new MockResponse('');
        });

        return new HookRequestExecutor($resolver, $this->createMock(HmacOpensslCrypter::class), $noPrivate, $plain, $privateHosts);
    }
}
