<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\GatewayInterface;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Request\RegisterWebhook;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The gateway's "tags" option on every object it makes at Stripe, as the
 * requests go on the wire (recorded, no call to Stripe): several sites share
 * one sandbox and tell their objects apart by metadata[site]. Parameters as
 * Stripe's OpenAPI description has them: Checkout's metadata,
 * payment_intent_data.metadata and subscription_data.metadata; metadata on
 * POST /v1/refunds, POST /v1/charges/{charge}/refund, /v1/accounts and
 * /v1/webhook_endpoints.
 */
final class StripeTagsTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** @var list<array{string, string, array<string, mixed>}> method, path, the form sent */
    private array $calls = [];

    public function testACheckoutSessionAndItsPaymentIntentCarryTheSite(): void
    {
        $this->gateway()->purchase($this->payment(['order' => 'B-42', 'site' => 'wineseller', 'env' => 'mine']));

        $sent = $this->sent('POST', '/v1/checkout/sessions');
        $expected = ['site' => 'solstay', 'env' => 'mine', 'order' => 'B-42'];
        self::assertEquals($expected, $sent['metadata'], 'the caller\'s metadata wins, but not on "site"');
        self::assertEquals($expected, Data::of($sent)->get('payment_intent_data.metadata'));
        self::assertSame('Autoliquidation', Data::of($sent)->get('payment_intent_data.description'), 'beside the notice');
    }

    public function testWithoutTagsTheSessionIsAsBefore(): void
    {
        $this->gateway([])->purchase($this->payment());

        $sent = $this->sent('POST', '/v1/checkout/sessions');
        self::assertArrayNotHasKey('metadata', $sent);
        self::assertSame(['description' => 'Autoliquidation'], $sent['payment_intent_data']);
    }

    public function testASubscriptionCarriesTheSite(): void
    {
        $this->gateway()->subscribe($this->payment(['plan' => 'D']));

        $sent = $this->sent('POST', '/v1/checkout/sessions');
        self::assertSame('subscription', $sent['mode']);
        self::assertEquals(['site' => 'solstay', 'env' => 'demo', 'plan' => 'D'], $sent['metadata']);
        self::assertEquals(['site' => 'solstay', 'env' => 'demo', 'plan' => 'D'], Data::of($sent)->get('subscription_data.metadata'));
    }

    public function testAPartialRefundCarriesTheSite(): void
    {
        $this->gateway()->refund('ch_test_1', Money::of(500, 'EUR'), 'refund-42');

        $sent = $this->sent('POST', '/v1/charges/ch_test_1/refund');
        self::assertSame('500', $sent['amount']);
        self::assertEquals(['site' => 'solstay', 'env' => 'demo'], $sent['metadata']);
    }

    public function testAWholeRefundCarriesTheSite(): void
    {
        $this->gateway()->refund('ch_test_1', null, 'refund-43');

        self::assertEquals(['charge' => 'ch_test_1', 'metadata' => ['site' => 'solstay', 'env' => 'demo']], $this->sent('POST', '/v1/refunds'));
    }

    public function testAConnectedAccountAndAWebhookEndpointCarryTheSite(): void
    {
        $gateway = $this->gateway();
        $gateway->createAccount('fr', 'lea@example.org', metadata: ['seller' => '7']);
        $gateway->execute(new RegisterWebhook('https://solstay.example/stripe/webhook'));

        self::assertEquals(['site' => 'solstay', 'env' => 'demo', 'seller' => '7'], $this->sent('POST', '/v1/accounts')['metadata']);
        self::assertEquals(['site' => 'solstay', 'env' => 'demo'], $this->sent('POST', '/v1/webhook_endpoints')['metadata']);
    }

    public function testAnEventGivesTheTagsBack(): void
    {
        $gateway = $this->gateway();

        $session = $gateway->notify(...$this->signed(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 1250, 'currency' => 'eur', 'metadata' => ['site' => 'wineseller', 'order' => 'W-1']]]]));
        self::assertSame(['site' => 'wineseller', 'order' => 'W-1'], $session->tags);
        self::assertTrue($session->isFromAnotherSite('solstay'), 'another site\'s session, sent to this endpoint by mistake');

        $invoice = $gateway->notify(...$this->signed(['id' => 'evt_2', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1', 'object' => 'invoice', 'metadata' => [], 'parent' => ['subscription_details' => ['metadata' => ['site' => 'solstay']]]]]]));
        self::assertSame('solstay', $invoice->site(), 'an invoice: its subscription\'s metadata');
        self::assertFalse($invoice->isFromAnotherSite('solstay'));

        $refund = $gateway->notify(...$this->signed(['id' => 'evt_3', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge']]]));
        self::assertSame([], $refund->tags, 'an object without metadata: no tags, not filtered');
    }

    /** @param array<string, mixed> $tags */
    private function gateway(array $tags = ['site' => 'solstay', 'env' => 'demo']): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $body = $options['body'] ?? '';
            if (\is_callable($body)) {
                $chunks = '';
                while ('' !== $chunk = Data::cast($body(8192))) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            parse_str(\is_string($body) ? $body : '', $sent);
            $this->calls[] = [$method, $path, Data::map($sent)];

            return new MockResponse((string) json_encode(match (true) {
                '/v1/checkout/sessions' === $path => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 1250, 'currency' => 'eur'],
                '/v1/charges/ch_test_1/refund' === $path => ['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur'],
                '/v1/refunds' === $path => ['id' => 're_test_2', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1250, 'currency' => 'eur'],
                '/v1/accounts' === $path => ['id' => 'acct_1', 'object' => 'account', 'country' => 'FR'],
                'GET' === $method && '/v1/webhook_endpoints' === $path => ['object' => 'list', 'data' => [], 'has_more' => false],
                '/v1/webhook_endpoints' === $path => ['id' => 'we_1', 'object' => 'webhook_endpoint', 'url' => 'https://solstay.example/stripe/webhook', 'enabled_events' => [], 'secret' => 'whsec_new'],
                default => ['error' => ['message' => 'No such resource: '.$method.' '.$path]],
            }), ['http_code' => str_starts_with($path, '/v1/') ? 200 : 404]);
        });

        return (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_x', 'webhook_secret' => self::SECRET] + ([] === $tags ? [] : ['tags' => $tags]));
    }

    /** @param array<string, scalar> $metadata */
    private function payment(array $metadata = []): Payment
    {
        return new Payment(
            amount: Money::of(1250, 'EUR'),
            reference: 'B-42',
            description: 'Two nights',
            customer: new Customer(email: 'camille@example.org'),
            lines: [new Line('Two nights', Money::of(1250, 'EUR'))],
            returnUrl: 'https://solstay.example/back',
            idempotencyKey: 'attempt-1',
            notice: 'Autoliquidation',
            metadata: $metadata,
        );
    }

    /** @return array<string, mixed> the form of the one request made so */
    private function sent(string $method, string $path): array
    {
        $calls = array_values(array_filter($this->calls, static fn (array $call) => $call[0] === $method && $call[1] === $path));
        self::assertCount(1, $calls, $method.' '.$path);

        return $calls[0][2];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{string, array<string, string>}
     */
    private function signed(array $event): array
    {
        $body = (string) json_encode($event);
        $time = time();

        return [$body, ['Stripe-Signature' => \sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, self::SECRET))]];
    }
}
