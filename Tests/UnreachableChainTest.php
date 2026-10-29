<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\Stripe\Api;
use Omnitrade\Exception\ProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Nothing of Stripe's credentials anywhere in what a log of a failed call writes: the messages,
 * the previous exception's (a copy of the HTTP client's, the secrets masked) and the traces'
 * arguments, which PHP keeps unless zend.exception_ignore_args is on.
 */
final class UnreachableChainTest extends TestCase
{
    private const SECRETS = ['sk_test_stripe-key-51Hx9q', 'whsec_stripe-webhook-3c7e2d'];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', false !== $this->ignoreArgs ? $this->ignoreArgs : '1');
    }

    /** @return iterable<string, array{\Closure(Api): mixed}> */
    public static function calls(): iterable
    {
        yield 'get' => [static fn (Api $api): mixed => $api->get('/v1/products', ['limit' => 3])];
        yield 'post' => [static fn (Api $api): mixed => $api->post('/v1/accounts', ['email' => 'a@example.org'])];
        yield 'omnipay' => [static fn (Api $api): mixed => $api->sendOmnipay($api->checkout()->fetchTransaction(['transactionReference' => 'cs_test_1']))];
    }

    /** @return iterable<string, array{\Closure(Api): mixed}> the calls an error answer fails */
    public static function errorCalls(): iterable
    {
        yield 'get' => [static fn (Api $api): mixed => $api->get('/v1/products', ['limit' => 3])];
        yield 'post' => [static fn (Api $api): mixed => $api->post('/v1/accounts', ['email' => 'a@example.org'])];
    }

    #[DataProvider('calls')]
    public function testNoPartOfAnUnreachableChainHoldsTheSecrets(\Closure $call): void
    {
        self::assertClean($call, static function (string $method, string $url): never {
            throw new TransportException(\sprintf('Could not resolve host: x for "%s".', $url), 6);
        });
    }

    #[DataProvider('errorCalls')]
    public function testAnErrorAnswerKeepsNoSecretInTheTraces(\Closure $call): void
    {
        self::assertClean($call, static fn (string $method, string $url): MockResponse => new MockResponse('{"message":"down"}', ['http_code' => 500]));
    }

    private static function assertClean(\Closure $call, \Closure $responses): void
    {
        $api = new Api('sk_test_stripe-key-51Hx9q', 'whsec_stripe-webhook-3c7e2d', new MockHttpClient($responses));
        try {
            $call($api);
            self::fail('no exception');
        } catch (ProviderException $e) {
            self::assertNotEmpty($e->getTrace());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                // A boolean, not assertStringNotContainsString(): a trace with its arguments is too large to export.
                $trace = print_r($one->getTrace(), true).$one->getTraceAsString();
                foreach (self::SECRETS as $secret) {
                    self::assertStringNotContainsString($secret, $one->getMessage());
                    self::assertFalse(str_contains($trace, $secret), \sprintf('The trace of %s holds "%s".', $one::class, $secret));
                }
            }
        }
    }
}
