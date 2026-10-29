<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Request\Authorize;
use Omnitrade\Request\Purchase;
use Omnitrade\Stripe\StripeGatewayFactory;
use Omnitrade\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StripeGatewayTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** @var list<array{string, string, string}> method, url, body */
    private array $calls = [];

    private function gateway(): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? '';
            if (\is_callable($body)) {
                $chunks = '';
                while ('' !== $chunk = $body(8192)) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $this->calls[] = [$method, $url, (string) $body];
            $path = (string) parse_url($url, \PHP_URL_PATH);

            return match (true) {
                'POST' === $method && '/v1/checkout/sessions' === $path => new MockResponse(json_encode(self::session('open', 'unpaid') + ['url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])),
                'GET' === $method && '/v1/checkout/sessions/cs_test_1' === $path => new MockResponse(json_encode(self::session('complete', 'paid') + ['payment_intent' => 'pi_test_1'])),
                // Stripe gives the payment intent's id unless it is expanded; its charge says what was refunded.
                'GET' === $method && '/v1/checkout/sessions/cs_test_refunded' === $path => new MockResponse(json_encode(['id' => 'cs_test_refunded'] + self::session('complete', 'paid') + ['payment_intent' => \in_array('payment_intent.latest_charge', self::expanded($url), true)
                    ? ['id' => 'pi_test_2', 'object' => 'payment_intent', 'amount' => 1250, 'latest_charge' => ['id' => 'ch_test_2', 'object' => 'charge', 'amount' => 1250, 'amount_refunded' => 1250, 'refunded' => true]]
                    : 'pi_test_2'])),
                'GET' === $method && '/v1/checkout/sessions/cs_test_gone' === $path => new MockResponse(json_encode(self::session('expired', 'unpaid'))),
                'GET' === $method && '/v1/payment_intents/pi_test_1' === $path => new MockResponse(json_encode(['id' => 'pi_test_1', 'object' => 'payment_intent', 'currency' => 'eur', 'latest_charge' => 'ch_test_1'])),
                'POST' === $method && '/v1/refunds' === $path => new MockResponse(json_encode(['id' => 're_test_2', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1250, 'currency' => 'eur', 'charge' => 'ch_test_1'])),
                'POST' === $method && '/v1/charges/ch_test_1/refund' === $path => new MockResponse(json_encode(['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur'])),
                default => new MockResponse(json_encode(['error' => ['message' => 'No such resource: '.$method.' '.$path]]), ['http_code' => 404]),
            };
        });

        return (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_x', 'webhook_secret' => self::SECRET]);
    }

    public function testAPurchaseOpensACheckoutSessionTheBuyerIsSentTo(): void
    {
        $gateway = $this->gateway();
        $transaction = $gateway->purchase(Fixtures::payment());

        self::assertTrue($transaction->isRedirect());
        self::assertSame('cs_test_1', $transaction->reference);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $transaction->redirectUrl);
        self::assertTrue($transaction->amount->equals(Money::of(1250, 'EUR')));

        [, , $body] = $this->calls[0];
        parse_str($body, $sent);
        self::assertSame('payment', $sent['mode']);
        self::assertSame('ORDER-1042', $sent['client_reference_id']);
        self::assertSame('camille@example.org', $sent['customer_email']);
        self::assertSame('1250', $sent['line_items'][0]['price_data']['unit_amount'], 'the line listed on the page');
        self::assertSame('Dix heures', $sent['line_items'][0]['price_data']['product_data']['name']);
        self::assertSame('Autoliquidation', $sent['custom_text']['submit']['message'], 'the notice on the page');
        self::assertSame('false', $sent['adaptive_pricing']['enabled']);
        self::assertStringContainsString('session={CHECKOUT_SESSION_ID}', $sent['success_url'], 'the braces as Stripe wants them, not URL-encoded');
        self::assertSame('https://shop.example/panier', $sent['cancel_url']);
        self::assertTrue($gateway->supports(Purchase::class));
        self::assertFalse($gateway->supports(Authorize::class), 'Checkout takes the money when the buyer pays');
    }

    public function testASessionIsFetchedAsWhereItStands(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->fetch('cs_test_1')->isPaid());
        self::assertSame(Status::EXPIRED, $gateway->fetch('cs_test_gone')->status);
    }

    /**
     * The session's payment intent and its latest charge are expanded on a fetch: a
     * refunded session reads REFUNDED, not PAID. Stripe's expand parameter, nested up to
     * four levels: https://docs.stripe.com/api/expanding_objects; a charge's
     * amount_refunded: https://docs.stripe.com/api/charges/object#charge_object-amount_refunded.
     */
    public function testARefundedSessionIsFetchedAsRefunded(): void
    {
        $transaction = $this->gateway()->fetch('cs_test_refunded');

        self::assertSame(Status::REFUNDED, $transaction->status);
        self::assertSame(1250, $transaction->refunded?->amount);
        self::assertContains('payment_intent.latest_charge', self::expanded($this->calls[0][1]));
    }

    /**
     * Shipping on top of the lines: the lines listed, the shipping as the session's shipping
     * rate (shipping_options[][shipping_rate_data], a fixed amount:
     * https://docs.stripe.com/api/checkout/sessions/create#create_checkout_session-shipping_options),
     * the session's total the payment's.
     */
    public function testACheckoutSessionChargesTheShippingOnTopOfTheLines(): void
    {
        $payment = new Payment(
            amount: Money::of(2750, 'EUR'),
            reference: 'ORDER-1043',
            lines: [new Line('Dix heures', Money::of(1200, 'EUR'), 2)],
            shipping: Money::of(350, 'EUR'),
            returnUrl: 'https://shop.example/retour',
        );
        $this->gateway()->purchase($payment);

        parse_str($this->calls[0][2], $sent);
        self::assertSame('1200', $sent['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('2', $sent['line_items'][0]['quantity']);
        $rate = $sent['shipping_options'][0]['shipping_rate_data'];
        self::assertSame('fixed_amount', $rate['type']);
        self::assertSame('350', $rate['fixed_amount']['amount']);
        self::assertSame('eur', $rate['fixed_amount']['currency']);
        self::assertSame(2750, self::charged($sent), 'what Stripe charges is the payment\'s amount');
    }

    /**
     * A discount: Checkout's line items take no negative amount and a discount needs a coupon
     * made beforehand (https://docs.stripe.com/api/checkout/sessions/create#create_checkout_session-discounts),
     * so the amount is charged as one line, under the payment's description.
     */
    public function testACheckoutSessionChargesTheAmountWhenTheLinesBalanceOnlyWithADiscount(): void
    {
        $payment = new Payment(
            amount: Money::of(2750, 'EUR'),
            reference: 'ORDER-1044',
            description: 'Commande n° 1044',
            lines: [new Line('Dix heures', Money::of(3000, 'EUR'))],
            shipping: Money::of(490, 'EUR'),
            discount: Money::of(740, 'EUR'),
            returnUrl: 'https://shop.example/retour',
        );
        $this->gateway()->purchase($payment);

        parse_str($this->calls[0][2], $sent);
        self::assertCount(1, $sent['line_items']);
        self::assertSame('Commande n° 1044', $sent['line_items'][0]['price_data']['product_data']['name']);
        self::assertArrayNotHasKey('shipping_options', $sent, 'the shipping is inside the one line');
        self::assertSame(2750, self::charged($sent));
    }

    public function testARefundGoesOnTheSessionsChargeWithItsIdempotencyKey(): void
    {
        $refund = $this->gateway()->refund('cs_test_1', Money::of(500, 'EUR'), 'refund-42');

        self::assertSame('re_test_1', $refund->reference);
        self::assertSame(Status::REFUNDED, $refund->status);
        self::assertSame(500, $refund->amount->amount);
        $post = array_values(array_filter($this->calls, fn ($c) => 'POST' === $c[0] && str_ends_with((string) parse_url($c[1], \PHP_URL_PATH), '/refund')))[0];
        self::assertStringEndsWith('/v1/charges/ch_test_1/refund', (string) parse_url($post[1], \PHP_URL_PATH), 'the session -> its payment intent -> its charge');
        parse_str($post[2], $sent);
        self::assertSame('500', $sent['amount']);
    }

    /**
     * A refund without an amount is the whole charge: Stripe's Create a refund, the amount left
     * out ("defaults to the entire charge amount", https://docs.stripe.com/api/refunds/create).
     */
    public function testARefundWithoutAnAmountRefundsTheWholeCharge(): void
    {
        $refund = $this->gateway()->refund('cs_test_1', null, 'refund-43');

        self::assertSame('re_test_2', $refund->reference);
        self::assertSame(Status::REFUNDED, $refund->status);
        self::assertSame(1250, $refund->amount->amount);
        $post = array_values(array_filter($this->calls, fn ($c) => 'POST' === $c[0]))[0];
        self::assertSame('/v1/refunds', parse_url($post[1], \PHP_URL_PATH));
        parse_str($post[2], $sent);
        self::assertSame(['charge' => 'ch_test_1'], $sent);
    }

    public function testANotificationIsCheckedThenRead(): void
    {
        $gateway = $this->gateway();
        $event = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => self::session('complete', 'paid')]]);
        $time = time();
        $header = sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$event, self::SECRET));

        $notification = $gateway->notify($event, ['Stripe-Signature' => $header]);
        self::assertSame('checkout.session.completed', $notification->event);
        self::assertSame('cs_test_1', $notification->reference);
        self::assertSame(Status::PAID, $notification->status);
        self::assertTrue($notification->transaction->isPaid());
        self::assertSame('evt_1', $notification->id);

        $this->expectException(InvalidNotificationException::class);
        $gateway->notify($event, ['Stripe-Signature' => sprintf('t=%d,v1=%s', $time, str_repeat('0', 64))]);
    }

    /**
     * The amount's currency narrows the methods: SEPA Direct Debit, Bancontact, iDEAL and EPS take
     * euros only (Stripe's payment method support, https://docs.stripe.com/payments/payment-methods/payment-method-support).
     */
    public function testThePaymentMethodsForAnAmountInAnotherCurrency(): void
    {
        $gateway = (new StripeGatewayFactory(new MockHttpClient()))->create(['api_key' => 'sk_test_x', 'payment_methods' => ['card', 'sepa_debit', 'ideal', 'bancontact', 'eps', 'klarna']]);
        $codes = static fn (array $methods): array => array_map(static fn ($m) => $m->code, $methods);

        self::assertSame(['card', 'sepa_debit', 'ideal', 'bancontact', 'eps', 'klarna'], $codes($gateway->paymentMethods(Money::of(1250, 'EUR'), 'NL')));
        self::assertSame(['card', 'klarna'], $codes($gateway->paymentMethods(Money::of(1250, 'GBP'), 'GB')));
        self::assertSame(['card', 'sepa_debit', 'ideal', 'bancontact', 'eps', 'klarna'], $codes($gateway->paymentMethods()), 'no amount: all of them');
    }

    public function testThePaymentMethodsAreWhatCheckoutOffers(): void
    {
        $methods = $this->gateway()->paymentMethods();
        self::assertSame('card', $methods[0]->code);
        self::assertContains('apple_pay', array_map(fn ($m) => $m->code, $methods));
    }

    /** @return list<string> the expand[] of a URL's query */
    private static function expanded(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return array_values((array) ($query['expand'] ?? []));
    }

    /** What Stripe charges for a session sent as $sent: its lines, plus its shipping rate. */
    private static function charged(array $sent): int
    {
        $total = 0;
        foreach ($sent['line_items'] as $line) {
            $total += (int) $line['price_data']['unit_amount'] * (int) $line['quantity'];
        }

        return $total + (int) ($sent['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] ?? 0);
    }

    private static function session(string $status, string $paymentStatus): array
    {
        return ['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $paymentStatus, 'amount_total' => 1250, 'currency' => 'eur', 'payment_method_types' => ['card'], 'created' => 1760000000, 'metadata' => []];
    }
}
