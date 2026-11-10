<?php

namespace Omnitrade\Stripe\Tests;

use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitrade\Exception\RateLimitedException;
use Omnitrade\Exception\UsageLimitReachedException;
use Omnitrade\Model\Money;
use Omnitrade\Registry;
use Omnitrade\Stripe\StripeGatewayFactory;
use Omnitrade\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stripe's usage policy (policy.json, declared in composer.json's
 * extra.omnisense.policy) and what a 429 does to a create: one request, with
 * the caller's Idempotency-Key, a RateLimitedException, nothing retried; the
 * next calls held without being sent while Stripe's wait lasts.
 */
final class StripePolicyTest extends TestCase
{
    /** @var list<array{string, string, array<mixed>}> method, url, headers */
    private array $calls = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnitrade/stripe', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('stripe', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame(25, $policy->limitsFor('purchase')[0]->max, 'the sandbox\'s global limit, the stricter');
        self::assertSame([], $policy->limitsFor('notify'), 'a webhook is no call to Stripe');
        self::assertCount(2, $policy->limitsFor('createAccount'));

        $registry = new Registry([new StripeGatewayFactory(new MockHttpClient())], ['card' => ['factory' => 'stripe', 'options' => ['api_key' => 'sk_test_x']]]);
        self::assertEquals($policy, $registry->policy('card'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'a rate limit' => ['rate_limit', 'global-rate'];
        yield 'a lock timeout (not processed either)' => ['lock_timeout', ''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testA429ToACheckoutSessionIsSurfacedAndNotRetried(string $code, string $reason): void
    {
        $gateway = $this->gateway(new MockResponse((string) json_encode(['error' => ['code' => $code, 'message' => 'Too many requests', 'type' => 'invalid_request_error']]), ['http_code' => 429, 'response_headers' => array_filter(['stripe-rate-limited-reason' => $reason])]));

        try {
            $gateway->purchase(Fixtures::payment());
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame('stripe', $e->provider);
            self::assertStringContainsString('Too many requests', $e->getMessage());
        }
        self::assertCount(1, $this->calls, 'sent once');
        self::assertContains('Idempotency-Key: attempt-1', $this->calls[0][2], 'retried by the caller with this key, Stripe makes it once');
    }

    public function testA429WithAWaitHoldsTheNextCallsUnsent(): void
    {
        $gateway = $this->gateway(new MockResponse('{"error":{"code":"rate_limit","message":"Too many requests"}}', ['http_code' => 429, 'response_headers' => ['retry-after' => '2']]));

        try {
            $gateway->refund('ch_test_1', null, 'refund-1');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(2, $e->retryAfter);
        }
        try {
            $gateway->refund('ch_test_1', Money::of(500, 'EUR'), 'refund-1');
            self::fail('held');
        } catch (UsageLimitReachedException) {
        }
        self::assertCount(1, $this->calls, 'the held refund was not sent');
    }

    private function gateway(MockResponse ...$answers): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, \is_array($options['headers'] ?? null) ? $options['headers'] : []];

            return array_shift($answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_x']);
    }
}
