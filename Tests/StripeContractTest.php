<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\GatewayFactoryInterface;
use Omnitrade\Stripe\StripeGatewayFactory;
use Omnitrade\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnitrade's conformance suite, on Stripe's recorded answers. */
final class StripeContractTest extends TestCase
{
    use GatewayContractTests;

    private const SECRET = 'whsec_contract';

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new StripeGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['api_key' => 'sk_test_contract', 'webhook_secret' => self::SECRET];
    }

    protected function transactionReference(): string
    {
        return 'cs_test_1';
    }

    protected function productReference(): string
    {
        return 'prod_A';
    }

    /** @return array<string, array<mixed>|callable> */
    protected function recordedAnswers(): array
    {
        $price = ['id' => 'price_75', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 2400, 'product' => 'prod_A', 'type' => 'one_time', 'recurring' => null, 'metadata' => []];
        $product = ['id' => 'prod_A', 'object' => 'product', 'active' => true, 'name' => 'Margaux', 'images' => [], 'metadata' => [], 'default_price' => $price, 'updated' => 1759500000];

        return [
            // A payment's session, or a setup's (SetupMethod) for the contract's customer.
            'POST /v1/checkout/sessions' => static fn (string $method, string $url, array $options): MockResponse => str_contains(\is_string($options['body'] ?? null) ? $options['body'] : '', 'mode=setup')
                ? self::json(['id' => 'cs_test_setup', 'object' => 'checkout.session', 'mode' => 'setup', 'status' => 'open', 'customer' => 'cus_contract', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_setup'])
                : self::json(self::session('open', 'unpaid') + ['url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
            'GET /v1/checkout/sessions/cs_test_setup' => ['id' => 'cs_test_setup', 'object' => 'checkout.session', 'mode' => 'setup', 'status' => 'complete', 'customer' => 'cus_contract', 'setup_intent' => [
                'id' => 'seti_test_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_contract',
                'payment_method' => ['id' => 'pm_contract', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_contract', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]],
            ]],
            'GET /v1/checkout/sessions/cs_test_1' => self::session('complete', 'paid') + ['payment_intent' => 'pi_test_1'],
            'GET /v1/payment_intents/pi_test_1' => ['id' => 'pi_test_1', 'object' => 'payment_intent', 'currency' => 'eur', 'latest_charge' => 'ch_test_1'],
            'POST /v1/charges/ch_test_1/refund' => ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur'],
            'GET /v1/products' => ['object' => 'list', 'data' => [$product], 'has_more' => false],
            'GET /v1/products/prod_A' => $product,
            'GET /v1/prices' => ['object' => 'list', 'data' => [$price], 'has_more' => false],
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    protected function signedNotification(): array
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => self::session('complete', 'paid')]], \JSON_THROW_ON_ERROR);
        $time = time();

        return [$body, ['Stripe-Signature' => \sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, self::SECRET))]];
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }

    /** @return array<string, mixed> */
    private static function session(string $status, string $paymentStatus): array
    {
        return ['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $paymentStatus, 'amount_total' => 1250, 'currency' => 'eur', 'payment_method_types' => ['card'], 'created' => 1760000000, 'metadata' => []];
    }
}
