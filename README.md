# omnitrade/stripe

Stripe for [omnisense/omnitrade](https://github.com/glitchr-omnisense/omnitrade): cards and wallets
through Checkout (Stripe's hosted page), what was paid, refunds, the webhook's events, and the
catalogue kept in Stripe's Products - on omnipay/stripe, through the application's HTTP client.

## Install and a first call

```sh
composer require omnitrade/stripe
```

No account for this first call: Stripe's answer is recorded (Symfony's `MockHttpClient` answers in
place of the API), so nothing leaves the process. With your own credentials, give the factory your
HTTP client (or none: it makes its own).

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Stripe\StripeGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Stripe's answer (the Checkout session), recorded: no account, and nothing leaves the process.
$http = new MockHttpClient(new MockResponse('{"id": "cs_test_1", "object": "checkout.session", "status": "open", "payment_status": "unpaid", "amount_total": 1250, "currency": "eur", "url": "https://checkout.stripe.com/c/pay/cs_test_1"}'));
$gateway = (new StripeGatewayFactory($http))->create(['api_key' => 'sk_test_not_a_key']);

$transaction = $gateway->purchase(new Payment(amount: Money::of(1250, 'EUR'), reference: 'ORDER-1042', returnUrl: 'https://shop.example/back'));
echo $transaction->status->value, ' ', $transaction->redirectUrl, "\n";   // pending https://checkout.stripe.com/c/pay/cs_test_1
```

A real payment needs a Stripe account: its secret key (below), a test key first.

## Configuration

```php
$gateway = (new StripeGatewayFactory($http))->create(['api_key' => '...', 'webhook_secret' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `omnisense/omnitrade`, `symfony/http-client` and Omnipay
(`omnipay/stripe`, with what Omnipay itself requires). In a Symfony application, the same through
the bundle's configuration:

```yaml
omnitrade:
    gateways:
        card:
            factory: stripe
            options:
                api_key: '%env(STRIPE_API_KEY)%'               # sk_live_... / sk_test_...
                webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%' # whsec_..., for notify()
                adaptive_pricing: false                        # true: the buyer's own currency may be offered
                payment_methods: []                            # ['card', 'sepa_debit']: the methods to insist on
```

`purchase()` opens a Checkout session and answers PENDING with the page's URL (`returnUrl`
required; Stripe appends `?session={CHECKOUT_SESSION_ID}` to it); `fetch()` reads the session
(paid, open, expired); `refund()` pays back on the session's charge, with an idempotency key;
`notify()` checks `Stripe-Signature` and reads the `checkout.session.*` events. No authorizations.

## Catalogue

`fetchProducts()` lists Stripe's Products (`starting_after`; a query through the products
search, `name~"…"`), `fetchProduct()` reads one by its `prod_…` id; each active Price is a
variant (the default price first) with its offer. Metadata are the attributes, `metadata.brand`
the brand; `images` the media. Stripe counts no stock: `fetchInventory()` is not supported.
`notify()` reads `product.*` and `price.*` into a `Notification` carrying the product. See
[docs/catalogue.md](docs/catalogue.md).

Credentials: a secret key from the Stripe Dashboard (Developers → API keys). The webhook
endpoint (`checkout.session.completed`, `checkout.session.async_payment_succeeded`,
`checkout.session.expired`, `checkout.session.async_payment_failed`) is optional, and the
application registers it itself: `RegisterWebhook($url)` answers its signing secret, to store
sealed and pass as `webhook_secret` ([docs/index.md](docs/index.md)). No console, no `stripe listen`.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Stripe, Inc. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Stripe's terms ([Stripe
Services Agreement](https://stripe.com/legal/ssa)), for obtaining whatever they require (an account,
a key, a licence or subscription, an attribution or a citation, a rate limit to respect), and for
any use made of what it returns. The authors publish it free of charge, make no commercial use of
it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

## Connect and subscriptions

Express accounts for those the platform pays (`createAccount()`, `accountLink()`,
`fetchAccount()`), payments sent to them as destination charges
(`Payment::$destination`, `::$applicationFee`), subscriptions through Checkout
(`subscribe()`, `fetchSubscription()`, `cancelSubscription()`, `subscriptionPortal()`), and the
`account.updated` and `customer.subscription.*` events read by `notify()`. See
[docs/connect.md](docs/connect.md).

## Saved methods (1.1)

A card saved once on Checkout's setup page (`SetupMethod`), listed (`FetchSavedMethods`), removed
(`RemoveSavedMethod`), and charged without a page by a `Payment` naming it (`savedMethod`): a
PaymentIntent confirmed off-session, the bank's 3-D Secure check, when it asks, through its own page
(a PENDING transaction's `redirectUrl`), `payment_intent.succeeded` saying when it is paid. The
site keeps the customer's and the method's ids, never a card number. See
[docs/index.md](docs/index.md#behaviour).

```php
use Omnitrade\Model\Customer;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\SetupMethod;

$setup = $stripe->execute(new SetupMethod(new Customer('alice@example.org'), 'https://shop.example/saved'))->getSetup();
// send the buyer to $setup->redirectUrl; keep $setup->customer (cus_...)
$card = $stripe->execute(new FetchSavedMethods($setup->customer))->getMethods()[0];   // "Visa •••• 4242"
$paid = $stripe->purchase(new Payment(Money::of(1990, 'EUR'), 'ORDER-7', customer: new Customer(reference: $setup->customer),
    returnUrl: 'https://shop.example/orders/7', idempotencyKey: 'order-7-attempt-1', savedMethod: $card->reference));
// PAID, REFUSED, or PENDING with the bank's page in $paid->redirectUrl
```
