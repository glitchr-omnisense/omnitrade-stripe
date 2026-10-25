<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\GatewayFactoryInterface;
use Omnitrade\Stripe\StripeGatewayFactory;
use Omnitrade\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
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

    protected function recordedAnswers(): array
    {
        $price = ['id' => 'price_75', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 2400, 'product' => 'prod_A', 'type' => 'one_time', 'recurring' => null, 'metadata' => []];
        $product = ['id' => 'prod_A', 'object' => 'product', 'active' => true, 'name' => 'Margaux', 'images' => [], 'metadata' => [], 'default_price' => $price, 'updated' => 1759500000];

        return [
            'POST /v1/checkout/sessions' => self::session('open', 'unpaid') + ['url' => 'https://checkout.stripe.com/c/pay/cs_test_1'],
            'GET /v1/checkout/sessions/cs_test_1' => self::session('complete', 'paid') + ['payment_intent' => 'pi_test_1'],
            'GET /v1/payment_intents/pi_test_1' => ['id' => 'pi_test_1', 'object' => 'payment_intent', 'currency' => 'eur', 'latest_charge' => 'ch_test_1'],
            'POST /v1/charges/ch_test_1/refund' => ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur'],
            'GET /v1/products' => ['object' => 'list', 'data' => [$product], 'has_more' => false],
            'GET /v1/products/prod_A' => $product,
            'GET /v1/prices' => ['object' => 'list', 'data' => [$price], 'has_more' => false],
        ];
    }

    protected function signedNotification(): ?array
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => self::session('complete', 'paid')]]);
        $time = time();

        return [$body, ['Stripe-Signature' => \sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, self::SECRET))]];
    }

    private static function session(string $status, string $paymentStatus): array
    {
        return ['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $paymentStatus, 'amount_total' => 1250, 'currency' => 'eur', 'payment_method_types' => ['card'], 'created' => 1760000000, 'metadata' => []];
    }
}
