<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\Status;
use Omnitrade\Request\Authorize;
use Omnitrade\Request\Purchase;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\StripeGatewayFactory;
use Omnitrade\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StripeGatewayTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** @var list<array{string, string, string, list<string>}> method, url, body, headers */
    private array $calls = [];

    /** @param array<string, mixed> $options */
    private function gateway(array $options = []): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? '';
            if (\is_callable($body)) {
                $chunks = '';
                while ('' !== $chunk = Data::cast($body(8192))) {
                    $chunks .= $chunk;
                }
                $body = $chunks;
            }
            $this->calls[] = [$method, $url, Data::cast($body), Data::texts($options['headers'] ?? [])];
            $path = (string) parse_url($url, \PHP_URL_PATH);

            return match (true) {
                'POST' === $method && '/v1/checkout/sessions' === $path => new MockResponse(self::json(self::session('open', 'unpaid') + ['url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])),
                'GET' === $method && '/v1/checkout/sessions/cs_test_1' === $path => new MockResponse(self::json(self::session('complete', 'paid') + ['payment_intent' => 'pi_test_1'])),
                // Stripe gives the payment intent's id unless it is expanded; its charge says what was refunded.
                'GET' === $method && '/v1/checkout/sessions/cs_test_refunded' === $path => new MockResponse(self::json(['id' => 'cs_test_refunded'] + self::session('complete', 'paid') + ['payment_intent' => \in_array('payment_intent.latest_charge', self::expanded($url), true)
                    ? ['id' => 'pi_test_2', 'object' => 'payment_intent', 'amount' => 1250, 'latest_charge' => ['id' => 'ch_test_2', 'object' => 'charge', 'amount' => 1250, 'amount_refunded' => 1250, 'refunded' => true]]
                    : 'pi_test_2'])),
                'GET' === $method && '/v1/checkout/sessions/cs_test_gone' === $path => new MockResponse(self::json(self::session('expired', 'unpaid'))),
                'GET' === $method && '/v1/payment_intents/pi_test_1' === $path => new MockResponse(self::json(['id' => 'pi_test_1', 'object' => 'payment_intent', 'currency' => 'eur', 'latest_charge' => 'ch_test_1'])),
                'POST' === $method && '/v1/refunds' === $path => new MockResponse(self::json(['id' => 're_test_2', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1250, 'currency' => 'eur', 'charge' => 'ch_test_1'])),
                'POST' === $method && '/v1/charges/ch_test_1/refund' === $path => new MockResponse(self::json(['id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur'])),
                default => new MockResponse(self::json(['error' => ['message' => 'No such resource: '.$method.' '.$path]]), ['http_code' => 404]),
            };
        });

        return (new StripeGatewayFactory($http))->create($options + ['api_key' => 'sk_test_x', 'webhook_secret' => self::SECRET]);
    }

    public function testEveryCallNamesItsApiVersion(): void
    {
        $gateway = $this->gateway();
        $gateway->purchase(Fixtures::payment());
        $gateway->fetch('cs_test_1');

        self::assertNotEmpty($this->calls);
        foreach ($this->calls as [$method, $url, , $headers]) {
            self::assertContains('Stripe-Version: 2020-08-27', $headers, $method.' '.$url.': the version this code is written against, not the account\'s default');
        }
    }

    public function testTheApiVersionIsAnOption(): void
    {
        $this->gateway(['api_version' => '2025-03-31.basil'])->purchase(Fixtures::payment());
        self::assertContains('Stripe-Version: 2025-03-31.basil', $this->call(0)[3]);

        $this->calls = [];
        $this->gateway(['api_version' => null])->purchase(Fixtures::payment());
        self::assertEmpty(preg_grep('/^Stripe-Version:/i', $this->call(0)[3]), 'null: the account\'s default');
    }

    public function testAPurchaseOpensACheckoutSessionTheBuyerIsSentTo(): void
    {
        $gateway = $this->gateway();
        $transaction = $gateway->purchase(Fixtures::payment());

        self::assertTrue($transaction->isRedirect());
        self::assertSame('cs_test_1', $transaction->reference);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $transaction->redirectUrl);
        self::assertNotNull($transaction->amount);
        self::assertTrue($transaction->amount->equals(Money::of(1250, 'EUR')));

        [, , $body] = $this->calls[0];
        parse_str($body, $query);
        $sent = Data::of($query);
        self::assertSame('payment', $sent->get('mode'));
        self::assertSame('ORDER-1042', $sent->get('client_reference_id'));
        self::assertSame('camille@example.org', $sent->get('customer_email'));
        self::assertSame('1250', $sent->get('line_items.0.price_data.unit_amount'), 'the line listed on the page');
        self::assertSame('Dix heures', $sent->get('line_items.0.price_data.product_data.name'));
        self::assertSame('Autoliquidation', $sent->get('custom_text.submit.message'), 'the notice on the page');
        self::assertSame('false', $sent->get('adaptive_pricing.enabled'));
        self::assertStringContainsString('session={CHECKOUT_SESSION_ID}', $sent->text('success_url'), 'the braces as Stripe wants them, not URL-encoded');
        self::assertSame('https://shop.example/panier', $sent->get('cancel_url'));
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

        parse_str($this->calls[0][2], $query);
        $sent = Data::of($query);
        self::assertSame('1200', $sent->get('line_items.0.price_data.unit_amount'));
        self::assertSame('2', $sent->get('line_items.0.quantity'));
        $rate = $sent->data('shipping_options.0.shipping_rate_data');
        self::assertSame('fixed_amount', $rate->get('type'));
        self::assertSame('350', $rate->get('fixed_amount.amount'));
        self::assertSame('eur', $rate->get('fixed_amount.currency'));
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

        parse_str($this->calls[0][2], $query);
        $sent = Data::of($query);
        self::assertCount(1, $sent->list('line_items'));
        self::assertSame('Commande n° 1044', $sent->get('line_items.0.price_data.product_data.name'));
        self::assertArrayNotHasKey('shipping_options', $query, 'the shipping is inside the one line');
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
        $event = self::json(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => self::session('complete', 'paid')]]);
        $time = time();
        $header = sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$event, self::SECRET));

        $notification = $gateway->notify($event, ['Stripe-Signature' => $header]);
        self::assertSame('checkout.session.completed', $notification->event);
        self::assertSame('cs_test_1', $notification->reference);
        self::assertSame(Status::PAID, $notification->status);
        self::assertTrue($notification->transaction?->isPaid());
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

        self::assertSame(['card', 'sepa_debit', 'ideal', 'bancontact', 'eps', 'klarna'], self::codes($gateway->paymentMethods(Money::of(1250, 'EUR'), 'NL')));
        self::assertSame(['card', 'klarna'], self::codes($gateway->paymentMethods(Money::of(1250, 'GBP'), 'GB')));
        self::assertSame(['card', 'sepa_debit', 'ideal', 'bancontact', 'eps', 'klarna'], self::codes($gateway->paymentMethods()), 'no amount: all of them');
    }

    public function testThePaymentMethodsAreWhatCheckoutOffers(): void
    {
        $methods = $this->gateway()->paymentMethods();
        self::assertSame('card', $methods[0]->code);
        self::assertContains('apple_pay', self::codes($methods));
    }

    /**
     * @param array<PaymentMethod> $methods
     *
     * @return array<string>
     */
    private static function codes(array $methods): array
    {
        return array_map(static fn (PaymentMethod $m) => $m->code, $methods);
    }

    /** @return array{string, string, string, list<string>} the call made $index-th */
    private function call(int $index): array
    {
        self::assertArrayHasKey($index, $this->calls);

        return $this->calls[$index];
    }

    /** @return list<string> the expand[] of a URL's query */
    private static function expanded(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return Data::texts($query['expand'] ?? []);
    }

    /** What Stripe charges for a session sent as $sent: its lines, plus its shipping rate. */
    private static function charged(Data $sent): int
    {
        $total = 0;
        foreach ($sent->list('line_items') as $line) {
            $total += $line->number('price_data.unit_amount') * $line->number('quantity');
        }

        return $total + $sent->number('shipping_options.0.shipping_rate_data.fixed_amount.amount');
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function session(string $status, string $paymentStatus): array
    {
        return ['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $paymentStatus, 'amount_total' => 1250, 'currency' => 'eur', 'payment_method_types' => ['card'], 'created' => 1760000000, 'metadata' => []];
    }
}
