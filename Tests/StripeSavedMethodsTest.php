<?php

namespace Omnitrade\Stripe\Tests;

use Omnitrade\Exception\ProviderException;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\Status;
use Omnitrade\Request\CompleteMethodSetup;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\SetupMethod;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\StripeGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Saved methods (1.1, the setup completed 1.2), on answers shaped as Stripe's (API 2020-08-27), no
 * real call: a card saved on Checkout's setup page, read from the session once the buyer is back,
 * listed, detached; a payment charging it off-session - paid at once,
 * refused, or held by the bank's 3-D Secure check and finished by the webhook.
 */
final class StripeSavedMethodsTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** @var list<array{string, string, array<string, mixed>, list<string>}> method, path, the form sent, the headers */
    private array $calls = [];

    /** @var string what POST /v1/payment_intents answers: paid, declined, authentication */
    private string $card = 'paid';

    private function gateway(): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str(Data::cast($options['body'] ?? ''), $query);
            $sent = Data::map($query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $get);
            $this->calls[] = [$method, $path, 'GET' === $method ? Data::map($get) : $sent, Data::texts($options['headers'] ?? [])];
            $json = static fn (array $data, int $status = 200) => new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status]);

            return match (true) {
                'POST' === $method && '/v1/customers' === $path => $json(['id' => 'cus_new', 'object' => 'customer', 'email' => $sent['email'] ?? null]),
                'POST' === $method && '/v1/checkout/sessions' === $path => $json(['id' => 'cs_test_setup', 'object' => 'checkout.session', 'mode' => 'setup', 'status' => 'open', 'customer' => $sent['customer'], 'url' => 'https://checkout.stripe.com/c/pay/cs_test_setup']),
                'GET' === $method && '/v1/checkout/sessions/cs_test_setup' === $path => $json(self::setupSession('complete', 'cus_new', 'succeeded', self::card('pm_new', 'cus_new', 'visa', '4242', 2030))),
                'GET' === $method && '/v1/checkout/sessions/cs_test_open' === $path => $json(self::setupSession('open', 'cus_new', 'requires_payment_method', null)),
                'GET' === $method && '/v1/checkout/sessions/cs_test_expired' === $path => $json(self::setupSession('expired', 'cus_new', 'canceled', null)),
                'GET' === $method && '/v1/checkout/sessions/cs_test_other' === $path => $json(self::setupSession('complete', 'cus_someone_else', 'succeeded', self::card('pm_other', 'cus_someone_else', 'visa', '1881', 2029))),
                'GET' === $method && '/v1/payment_methods' === $path => $json(['object' => 'list', 'data' => [self::card('pm_new', 'cus_new', 'visa', '4242', 2030), self::card('pm_old', 'cus_new', 'mastercard', '4444', 2027)]]),
                'GET' === $method && '/v1/payment_methods/pm_new' === $path => $json(self::card('pm_new', 'cus_new', 'visa', '4242', 2030)),
                'GET' === $method && '/v1/payment_methods/pm_other' === $path => $json(self::card('pm_other', 'cus_someone_else', 'visa', '1881', 2029)),
                'POST' === $method && '/v1/payment_methods/pm_new/detach' === $path => $json(['customer' => null] + self::card('pm_new', 'cus_new', 'visa', '4242', 2030)),
                'POST' === $method && '/v1/payment_intents' === $path => match ($this->card) {
                    'paid' => $json(self::intent('succeeded')),
                    'declined' => $json(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.', 'payment_intent' => self::intent('requires_payment_method', ['last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']])]], 402),
                    default => $json(['error' => ['type' => 'card_error', 'code' => 'authentication_required', 'decline_code' => 'authentication_required', 'message' => 'Your card was declined. This transaction requires authentication.', 'payment_intent' => self::intent('requires_payment_method', ['last_payment_error' => ['code' => 'authentication_required']])]], 402),
                },
                'POST' === $method && '/v1/payment_intents/pi_test_1/confirm' === $path => $json(self::intent('requires_action', ['next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3d_secure_2/hosted?pi=pi_test_1', 'return_url' => $sent['return_url']]]])),
                'GET' === $method && '/v1/payment_intents/pi_test_1' === $path => $json(self::intent('succeeded', ['charges' => ['object' => 'list', 'data' => [['id' => 'ch_test_1', 'amount_refunded' => 0]]]])),
                default => $json(['error' => ['message' => 'No such resource: '.$method.' '.$path, 'code' => 'resource_missing']], 404),
            };
        });

        return (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_x', 'webhook_secret' => self::SECRET, 'tags' => ['site' => 'forge']]);
    }

    public function testACardIsSavedOnCheckoutsSetupPageForACustomerMadeForIt(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(SetupMethod::class));
        self::assertTrue($gateway->capabilities()->supports('setupMethod'));

        $setup = $gateway->execute(new SetupMethod(new Customer('alice@example.org', 'Alice'), 'https://forge.example/hub/saved', idempotencyKey: 'setup-1', metadata: ['user' => '42'], locale: 'fr-FR'))->getSetup();

        self::assertNotNull($setup);
        self::assertSame('cus_new', $setup->customer);
        self::assertSame('cs_test_setup', $setup->reference);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_setup', $setup->redirectUrl);
        [[$method, $path, $customer, $headers], [, $sessionPath, $session, $sessionHeaders]] = $this->calls;
        self::assertSame(['POST', '/v1/customers'], [$method, $path]);
        self::assertSame(['email' => 'alice@example.org', 'name' => 'Alice', 'metadata' => ['site' => 'forge', 'user' => '42']], $customer);
        self::assertContains('Idempotency-Key: setup-1:customer', $headers);
        self::assertSame('/v1/checkout/sessions', $sessionPath);
        self::assertSame('setup', $session['mode']);
        self::assertSame('cus_new', $session['customer']);
        self::assertSame(['card'], $session['payment_method_types']);
        self::assertSame('https://forge.example/hub/saved', $session['success_url']);
        self::assertSame('fr', $session['locale']);
        self::assertSame(['site' => 'forge', 'user' => '42'], Data::of($session)->get('setup_intent_data.metadata'));
        self::assertContains('Idempotency-Key: setup-1', $sessionHeaders);

        $before = \count($this->calls);
        $gateway->execute(new SetupMethod(new Customer('alice@example.org', reference: 'cus_new'), 'https://forge.example/hub/saved'));
        self::assertCount($before + 1, $this->calls, 'a known customer: no new one');
        self::assertSame(['POST', '/v1/checkout/sessions', 'cus_new'], [$this->calls[$before][0], $this->calls[$before][1], Data::of($this->calls[$before][2])->get('customer')]);
    }

    /**
     * Back on success_url, nothing is left to do at Stripe: Checkout's SetupIntent attached the
     * method to the customer when the buyer confirmed. The session is read with its SetupIntent's
     * method expanded (https://docs.stripe.com/api/checkout/sessions/object#checkout_session_object-setup_intent,
     * https://docs.stripe.com/api/setup_intents/object#setup_intent_object-payment_method).
     */
    public function testBackOnTheReturnUrlTheSessionsSavedMethodIsAnswered(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(CompleteMethodSetup::class));
        self::assertTrue($gateway->capabilities()->supports('completeMethodSetup'));

        $method = $gateway->execute(new CompleteMethodSetup('cs_test_setup', 'cus_new'))->getMethod();

        self::assertNotNull($method);
        self::assertSame(['pm_new', 'cus_new', 'Visa •••• 4242', PaymentMethod::CARD, 12, 2030], [$method->reference, $method->customer, $method->label, $method->kind, $method->expiryMonth, $method->expiryYear]);
        self::assertCount(1, $this->calls, 'a read, nothing sent');
        self::assertSame(['GET', '/v1/checkout/sessions/cs_test_setup'], [$this->calls[0][0], $this->calls[0][1]]);
        self::assertSame(['setup_intent.payment_method'], $this->calls[0][2]['expand'] ?? null);
        self::assertSame('pm_new', $gateway->execute(new CompleteMethodSetup('cs_test_setup', 'cus_new'))->getMethod()?->reference, 'asked twice, the same method');
        self::assertSame('pm_new', $gateway->execute(new CompleteMethodSetup('cs_test_setup'))->getMethod()?->reference, 'no customer named: not checked');
    }

    public function testASetupNotFinishedOrAnotherCustomersSavesNothing(): void
    {
        $gateway = $this->gateway();
        foreach ([
            ['cs_test_open', 'cus_new', CompleteMethodSetup::NOT_COMPLETED],
            ['cs_test_expired', 'cus_new', CompleteMethodSetup::NOT_COMPLETED],
            ['cs_test_other', 'cus_new', CompleteMethodSetup::NOT_THIS_CUSTOMER],
            ['cs_test_unknown', 'cus_new', 'resource_missing'],
        ] as [$reference, $customer, $code]) {
            try {
                $gateway->execute(new CompleteMethodSetup($reference, $customer));
                self::fail($reference.' completed');
            } catch (ProviderException $e) {
                self::assertSame($code, $e->providerCode, $reference);
            }
        }
        $this->calls = [];
        foreach (['pm_new', '../v1/customers', 'cs_test_setup/../x'] as $reference) {
            try {
                $gateway->execute(new CompleteMethodSetup($reference, 'cus_new'));
                self::fail($reference.' completed');
            } catch (ProviderException $e) {
                self::assertSame(CompleteMethodSetup::NOT_THIS_CUSTOMER, $e->providerCode);
            }
        }
        self::assertSame([], $this->calls, 'not a session\'s id: nothing asked');
    }

    public function testTheCustomersMethodsAreListedAndOnlyTheirsDetached(): void
    {
        $gateway = $this->gateway();

        $methods = $gateway->execute(new FetchSavedMethods('cus_new'))->getMethods();

        self::assertSame(['customer' => 'cus_new', 'type' => 'card', 'limit' => '100'], $this->calls[0][2]);
        self::assertCount(2, $methods);
        self::assertSame('pm_new', $methods[0]->reference);
        self::assertSame('Visa •••• 4242', $methods[0]->label);
        self::assertSame('Mastercard •••• 4444', $methods[1]->label);
        self::assertSame(PaymentMethod::CARD, $methods[0]->kind);
        self::assertSame([12, 2030, 'cus_new'], [$methods[0]->expiryMonth, $methods[0]->expiryYear, $methods[0]->customer]);

        self::assertTrue($gateway->execute(new RemoveSavedMethod('cus_new', 'pm_new'))->isRemoved());
        self::assertSame(['POST', '/v1/payment_methods/pm_new/detach'], [$this->calls[2][0], $this->calls[2][1]]);

        $this->calls = [];
        try {
            $gateway->execute(new RemoveSavedMethod('cus_new', 'pm_other'));
            self::fail('another customer\'s card is not detached');
        } catch (ProviderException $e) {
            self::assertSame('not_this_customer', $e->providerCode);
        }
        self::assertCount(1, $this->calls, 'read, never detached');
    }

    public function testASavedCardIsChargedOffSessionOnce(): void
    {
        $gateway = $this->gateway();

        $paid = $gateway->purchase(self::payment());

        self::assertSame(Status::PAID, $paid->status);
        self::assertSame('pi_test_1', $paid->reference);
        self::assertSame(1990, $paid->amount?->amount);
        self::assertNull($paid->redirectUrl);
        self::assertCount(1, $this->calls);
        [$method, $path, $sent, $headers] = $this->calls[0];
        self::assertSame(['POST', '/v1/payment_intents'], [$method, $path]);
        self::assertSame(['1990', 'eur', 'cus_new', 'pm_new', 'true', 'true'], [$sent['amount'], $sent['currency'], $sent['customer'], $sent['payment_method'], $sent['off_session'], $sent['confirm']]);
        self::assertSame(['site' => 'forge', 'order' => 'F-7', 'reference' => 'F-7'], $sent['metadata']);
        self::assertContains('Idempotency-Key: hub-purchase-7', $headers);
    }

    public function testARefusedCardIsARefusedTransactionWithItsDeclineCode(): void
    {
        $gateway = $this->gateway();
        $this->card = 'declined';

        $refused = $gateway->purchase(self::payment());

        self::assertSame(Status::REFUSED, $refused->status);
        self::assertSame('pi_test_1', $refused->reference);
        self::assertSame('insufficient_funds', $refused->providerCode);
        self::assertSame('Your card has insufficient funds.', $refused->message);
        self::assertCount(1, $this->calls, 'no second try');
    }

    public function testTheBanksCheckSendsTheBuyerToItsPageThenTheWebhookSaysPaid(): void
    {
        $gateway = $this->gateway();
        $this->card = 'authentication';

        $pending = $gateway->purchase(self::payment());

        self::assertSame(Status::PENDING, $pending->status);
        self::assertTrue($pending->isRedirect());
        self::assertSame('https://hooks.stripe.com/3d_secure_2/hosted?pi=pi_test_1', $pending->redirectUrl);
        self::assertSame('pi_test_1', $pending->reference);
        [, $path, $sent, $headers] = $this->calls[1];
        self::assertSame('/v1/payment_intents/pi_test_1/confirm', $path);
        self::assertSame(['payment_method' => 'pm_new', 'return_url' => 'https://forge.example/hub/purchases/7/done'], $sent);
        self::assertContains('Idempotency-Key: hub-purchase-7:confirm', $headers);

        // Stripe reports the off-session attempt as failed while the buyer is on the bank's page: not a refusal.
        $asked = $this->notify($gateway, 'payment_intent.payment_failed', self::intent('requires_payment_method', ['last_payment_error' => ['code' => 'authentication_required', 'decline_code' => 'authentication_required']]));
        self::assertSame(Status::PENDING, $asked->status);
        $declined = $this->notify($gateway, 'payment_intent.payment_failed', self::intent('requires_payment_method', ['last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds']]));
        self::assertSame(Status::REFUSED, $declined->status);
        self::assertSame('insufficient_funds', $declined->transaction?->providerCode);

        $notification = $this->notify($gateway, 'payment_intent.succeeded', self::intent('succeeded'));
        self::assertSame('pi_test_1', $notification->reference);
        self::assertSame(Status::PAID, $notification->status);
        self::assertSame(1990, $notification->transaction?->amount?->amount);
        self::assertSame('forge', $notification->site());

        $fetched = $gateway->fetch('pi_test_1');
        self::assertSame(Status::PAID, $fetched->status);
        self::assertSame(['GET', '/v1/payment_intents/pi_test_1'], [$this->calls[2][0], $this->calls[2][1]]);
    }

    public function testWithoutAPageToComeBackToTheBanksCheckIsARefusal(): void
    {
        $gateway = $this->gateway();
        $this->card = 'authentication';
        $payment = self::payment();

        $refused = $gateway->purchase(new Payment($payment->amount, $payment->reference, customer: $payment->customer, idempotencyKey: 'hub-purchase-8', savedMethod: 'pm_new'));

        self::assertSame(Status::REFUSED, $refused->status);
        self::assertSame('authentication_required', $refused->providerCode);
        self::assertCount(1, $this->calls, 'nothing confirmed');
    }

    /** @param array<string, mixed> $object */
    private function notify(GatewayInterface $gateway, string $type, array $object): \Omnitrade\Model\Notification
    {
        $event = json_encode(['id' => 'evt_'.bin2hex(random_bytes(4)), 'type' => $type, 'data' => ['object' => $object]], \JSON_THROW_ON_ERROR);
        $time = time();

        return $gateway->notify($event, ['Stripe-Signature' => sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$event, self::SECRET))]);
    }

    private static function payment(): Payment
    {
        return new Payment(
            Money::of(1990, 'EUR'),
            'F-7',
            'Forge Seal, one seat',
            new Customer('alice@example.org', reference: 'cus_new'),
            returnUrl: 'https://forge.example/hub/purchases/7/done',
            idempotencyKey: 'hub-purchase-7',
            metadata: ['order' => 'F-7'],
            savedMethod: 'pm_new',
        );
    }

    /**
     * A Checkout session in setup mode, its SetupIntent and that intent's method expanded.
     *
     * @param array<string, mixed>|null $method
     *
     * @return array<string, mixed>
     */
    private static function setupSession(string $status, string $customer, string $intentStatus, ?array $method): array
    {
        return ['id' => 'cs_test_setup', 'object' => 'checkout.session', 'mode' => 'setup', 'status' => $status, 'payment_status' => 'no_payment_required', 'customer' => $customer, 'url' => 'complete' === $status ? null : 'https://checkout.stripe.com/c/pay/cs_test_setup', 'metadata' => ['site' => 'forge'],
            'setup_intent' => ['id' => 'seti_test_1', 'object' => 'setup_intent', 'status' => $intentStatus, 'usage' => 'off_session', 'customer' => $customer, 'payment_method' => $method]];
    }

    /** @return array<string, mixed> */
    private static function card(string $id, string $customer, string $brand, string $last4, int $year): array
    {
        return ['id' => $id, 'object' => 'payment_method', 'type' => 'card', 'customer' => $customer, 'created' => 1763000000, 'card' => ['brand' => $brand, 'last4' => $last4, 'exp_month' => 12, 'exp_year' => $year, 'country' => 'FR']];
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private static function intent(string $status, array $more = []): array
    {
        return $more + ['id' => 'pi_test_1', 'object' => 'payment_intent', 'status' => $status, 'amount' => 1990, 'currency' => 'eur', 'customer' => 'cus_new', 'payment_method' => 'pm_new', 'payment_method_types' => ['card'], 'created' => 1763000100, 'metadata' => ['site' => 'forge', 'order' => 'F-7', 'reference' => 'F-7']];
    }
}
