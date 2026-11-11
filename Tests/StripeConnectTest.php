<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Subscription;
use Omnitrade\Request\AccountLink;
use Omnitrade\Request\CancelSubscription;
use Omnitrade\Request\CreateAccount;
use Omnitrade\Request\FetchAccount;
use Omnitrade\Request\Subscribe;
use Omnitrade\Request\SubscriptionPortal;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Connect (Express accounts, destination charges) and subscriptions, on answers shaped as Stripe's: no real call. */
final class StripeConnectTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** @var list<array{string, string, array<string, mixed>, array<mixed>}> method, path, the form sent, the options */
    private array $calls = [];

    private function gateway(): \Omnitrade\GatewayInterface
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
            parse_str(Data::cast($body), $query);
            $sent = Data::map($query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $path, $sent, $options];
            $json = static fn (array $data, int $status = 200) => new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status]);

            return match (true) {
                'POST' === $method && '/v1/accounts' === $path => $json(self::account(false)),
                'GET' === $method && '/v1/accounts/acct_1' === $path => $json(self::account(true)),
                'POST' === $method && '/v1/account_links' === $path => $json(['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/e/acct_1/abc', 'expires_at' => 1760000300]),
                'POST' === $method && '/v1/checkout/sessions' === $path => $json(['id' => 'cs_test_9', 'object' => 'checkout.session', 'mode' => $sent['mode'], 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 5000, 'currency' => 'eur', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_9', 'metadata' => $sent['metadata'] ?? []]),
                'GET' === $method && '/v1/subscriptions/sub_1' === $path => $json(self::subscription('active')),
                'POST' === $method && '/v1/subscriptions/sub_1' === $path => $json(['cancel_at_period_end' => true] + self::subscription('active')),
                'DELETE' === $method && '/v1/subscriptions/sub_1' === $path => $json(['canceled_at' => 1760001000] + self::subscription('canceled')),
                'POST' === $method && '/v1/billing_portal/sessions' === $path => $json(['object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1']),
                default => $json(['error' => ['message' => 'No such resource: '.$method.' '.$path, 'code' => 'resource_missing']], 404),
            };
        });

        return (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_x', 'webhook_secret' => self::SECRET]);
    }

    public function testAnExpressAccountIsOpenedThenCompletedOnStripesPage(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(CreateAccount::class));
        self::assertTrue($gateway->supports(AccountLink::class));
        self::assertTrue($gateway->supports(FetchAccount::class));

        $account = $gateway->createAccount('fr', 'lea@example.org', metadata: ['holder' => '42']);
        self::assertSame('acct_1', $account->reference);
        self::assertSame('express', $account->type);
        self::assertFalse($account->isReady());
        self::assertSame(['external_account'], $account->requirements);

        [, , $sent, $options] = $this->calls[0];
        self::assertSame('express', $sent['type']);
        self::assertSame('FR', $sent['country']);
        self::assertSame('lea@example.org', $sent['email']);
        self::assertSame('true', Data::of($sent)->get('capabilities.transfers.requested'));
        self::assertSame('42', Data::of($sent)->get('metadata.holder'));
        self::assertContains('Authorization: Bearer sk_test_x', Data::texts($options['headers'] ?? []));

        $url = $gateway->accountLink('acct_1', 'https://site.example/retour', 'https://site.example/reprendre');
        self::assertSame('https://connect.stripe.com/setup/e/acct_1/abc', $url);
        self::assertSame(['account' => 'acct_1', 'return_url' => 'https://site.example/retour', 'refresh_url' => 'https://site.example/reprendre', 'type' => 'account_onboarding'], $this->calls[1][2]);

        $ready = $gateway->fetchAccount('acct_1');
        self::assertTrue($ready->isReady());
        self::assertSame('EUR', $ready->defaultCurrency);
    }

    public function testAPaymentSentToAConnectedAccountKeepsThePlatformsFee(): void
    {
        $gateway = $this->gateway();
        $payment = new Payment(
            amount: Money::of(5000, 'EUR'),
            reference: 'GIFT-7',
            description: 'Participation - Voyage de noces',
            customer: new Customer(email: 'tom@example.org'),
            lines: [new Line('Participation', Money::of(5000, 'EUR'))],
            returnUrl: 'https://site.example/merci',
            destination: 'acct_1',
            applicationFee: Money::of(150, 'EUR'),
        );
        $transaction = $gateway->purchase($payment);
        self::assertTrue($transaction->isRedirect());

        [, $path, $sent] = $this->calls[0];
        self::assertSame('/v1/checkout/sessions', $path);
        self::assertSame('payment', $sent['mode']);
        self::assertSame('acct_1', Data::of($sent)->get('payment_intent_data.transfer_data.destination'), 'a destination charge');
        self::assertSame('150', Data::of($sent)->get('payment_intent_data.application_fee_amount'));
    }

    public function testAFeeNeedsADestinationAndFitsTheAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Payment(Money::of(5000, 'EUR'), 'X', applicationFee: Money::of(150, 'EUR'));
    }

    public function testAFeeLargerThanThePaymentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Payment(Money::of(100, 'EUR'), 'X', destination: 'acct_1', applicationFee: Money::of(150, 'EUR'));
    }

    public function testASubscriptionStartsOnCheckout(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(Subscribe::class));
        $payment = new Payment(Money::of(1900, 'EUR'), 'PLAN-D-42', 'Formule D', new Customer(email: 'lea@example.org'), returnUrl: 'https://site.example/retour', idempotencyKey: 'sub-attempt-1', metadata: ['order' => 'PLAN-D-42'], locale: 'fr-FR');

        $transaction = $gateway->subscribe($payment, 'month');
        self::assertTrue($transaction->isRedirect());
        self::assertSame('cs_test_9', $transaction->reference);

        [, , $sent, $options] = $this->calls[0];
        self::assertSame('subscription', $sent['mode']);
        self::assertSame('1900', Data::of($sent)->get('line_items.0.price_data.unit_amount'));
        self::assertSame('month', Data::of($sent)->get('line_items.0.price_data.recurring.interval'));
        self::assertSame('Formule D', Data::of($sent)->get('line_items.0.price_data.product_data.name'));
        self::assertSame('PLAN-D-42', Data::of($sent)->get('subscription_data.metadata.order'), 'the metadata follows the subscription');
        self::assertSame('lea@example.org', $sent['customer_email']);
        self::assertSame('fr', $sent['locale']);
        self::assertStringContainsString('session={CHECKOUT_SESSION_ID}', Data::cast($sent['success_url'] ?? null));
        self::assertContains('Idempotency-Key: sub-attempt-1', Data::texts($options['headers'] ?? []));

        $gateway->execute(new Subscribe($payment, 'year', 1, 'price_yearly', 14, 'cus_1'));
        $sent = $this->calls[1][2];
        self::assertSame('price_yearly', Data::of($sent)->get('line_items.0.price'), 'a price kept at Stripe');
        self::assertArrayNotHasKey('price_data', Data::of($sent)->data('line_items.0')->toArray());
        self::assertSame('14', Data::of($sent)->get('subscription_data.trial_period_days'));
        self::assertSame('cus_1', $sent['customer']);
        self::assertArrayNotHasKey('customer_email', $sent);
    }

    public function testASubscriptionIsReadStoppedAndManaged(): void
    {
        $gateway = $this->gateway();
        $subscription = $gateway->fetchSubscription('sub_1');
        self::assertTrue($subscription->isActive());
        self::assertSame('cus_1', $subscription->customer);
        self::assertNotNull($subscription->amount);
        self::assertTrue($subscription->amount->equals(Money::of(1900, 'EUR')));
        self::assertSame('month', $subscription->interval);
        self::assertSame(1762592000, $subscription->currentPeriodEnd?->getTimestamp());
        self::assertSame('PLAN-D-42', $subscription->metadata['order']);

        self::assertTrue($gateway->supports(CancelSubscription::class));
        $ending = $gateway->cancelSubscription('sub_1');
        self::assertTrue($ending->cancelAtPeriodEnd);
        self::assertTrue($ending->isActive(), 'what was paid for is kept until the period ends');
        self::assertSame(['POST', '/v1/subscriptions/sub_1', ['cancel_at_period_end' => 'true']], \array_slice($this->calls[1], 0, 3));

        $stopped = $gateway->cancelSubscription('sub_1', false);
        self::assertSame(Subscription::CANCELLED, $stopped->status);
        self::assertSame('DELETE', $this->calls[2][0]);

        self::assertTrue($gateway->supports(SubscriptionPortal::class));
        self::assertSame('https://billing.stripe.com/p/session/test_1', $gateway->subscriptionPortal('cus_1', 'https://site.example/compte', 'fr'));
        self::assertSame(['customer' => 'cus_1', 'return_url' => 'https://site.example/compte', 'locale' => 'fr'], $this->calls[3][2]);
    }

    public function testStripesErrorsSurface(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/No such resource/');
        $this->gateway()->fetchAccount('acct_unknown');
    }

    public function testTheWebhookCarriesTheAccountAndTheSubscription(): void
    {
        $gateway = $this->gateway();

        $notification = $gateway->notify(...$this->signed(['id' => 'evt_1', 'type' => 'account.updated', 'account' => 'acct_1', 'data' => ['object' => self::account(true)]]));
        self::assertTrue($notification->isAccount());
        self::assertSame('acct_1', $notification->reference);
        self::assertTrue($notification->account?->isReady());
        self::assertNull($notification->status);
        self::assertFalse($notification->isCatalogue());

        $notification = $gateway->notify(...$this->signed(['id' => 'evt_2', 'type' => 'customer.subscription.deleted', 'data' => ['object' => self::subscription('canceled')]]));
        self::assertTrue($notification->isSubscription());
        self::assertSame('sub_1', $notification->reference);
        self::assertTrue($notification->subscription?->isCancelled());

        $session = ['id' => 'cs_test_9', 'object' => 'checkout.session', 'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 1900, 'currency' => 'eur', 'subscription' => 'sub_1', 'customer' => 'cus_1', 'metadata' => ['order' => 'PLAN-D-42']];
        $notification = $gateway->notify(...$this->signed(['id' => 'evt_3', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]));
        self::assertNotNull($notification->transaction);
        self::assertTrue($notification->transaction->isPaid());
        self::assertSame('sub_1', $notification->transaction->raw['subscription']);
        self::assertSame('cus_1', $notification->transaction->raw['customer']);
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{string, array<string, string>}
     */
    private function signed(array $event): array
    {
        $body = json_encode($event, \JSON_THROW_ON_ERROR);
        $time = time();

        return [$body, ['Stripe-Signature' => sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, self::SECRET))]];
    }

    /** @return array<string, mixed> */
    private static function account(bool $ready): array
    {
        return [
            'id' => 'acct_1', 'object' => 'account', 'type' => 'express', 'country' => 'FR', 'email' => 'lea@example.org', 'default_currency' => 'eur',
            'details_submitted' => $ready, 'charges_enabled' => $ready, 'payouts_enabled' => $ready,
            'requirements' => ['currently_due' => $ready ? [] : ['external_account'], 'past_due' => []],
            'metadata' => ['holder' => '42'],
        ];
    }

    /** @return array<string, mixed> */
    private static function subscription(string $status): array
    {
        return [
            'id' => 'sub_1', 'object' => 'subscription', 'status' => $status, 'customer' => 'cus_1', 'currency' => 'eur', 'cancel_at_period_end' => false,
            'current_period_start' => 1760000000, 'current_period_end' => 1762592000,
            'items' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_1', 'unit_amount' => 1900, 'currency' => 'eur', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]],
            'metadata' => ['order' => 'PLAN-D-42'],
        ];
    }
}
