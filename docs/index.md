# omnitrade/stripe

Stripe for [omnisense/omnitrade](https://github.com/glitchr-omnisense/omnitrade): cards and wallets
through Checkout (Stripe's hosted page), what was paid, refunds, the webhook's events, the
catalogue kept in Stripe's Products and Prices, Connect (Express accounts and destination
charges) and subscriptions (Checkout in subscription mode, the customer portal).

The payments go through [Omnipay](https://omnipay.thephpleague.com)'s `omnipay/stripe` (its
Checkout and PaymentIntents gateways), on the application's HTTP client through a PSR-18 bridge;
the rest are plain calls of Stripe's REST API on that same client. Everything is on
`https://api.stripe.com` (`Omnitrade\Stripe\Api::BASE_URI`): Stripe has no sandbox address, a test
key (`sk_test_...`) is test mode.

| Operation | Stripe API | Path | Through |
|---|---|---|---|
| purchase | Checkout Sessions, `mode=payment` | `POST /v1/checkout/sessions` | omnipay |
| fetch | Checkout Sessions | `GET /v1/checkout/sessions/{id}` | omnipay |
| refund | the session, its payment intent, then the charge | `GET /v1/checkout/sessions/{id}`, `GET /v1/payment_intents/{id}`, `POST /v1/charges/{id}/refund` | omnipay |
| products | Products, Products search, Prices | `GET /v1/products`, `GET /v1/products/search`, `GET /v1/products/{id}`, `GET /v1/prices` | REST |
| connected accounts | Accounts, Account Links | `POST /v1/accounts`, `POST /v1/account_links`, `GET /v1/accounts/{id}` | REST |
| subscriptions | Checkout Sessions, `mode=subscription`; Subscriptions; Billing portal | `POST /v1/checkout/sessions`, `GET`/`POST`/`DELETE /v1/subscriptions/{id}`, `POST /v1/billing_portal/sessions` | REST |

## Installation

```sh
composer require omnitrade/stripe
```

Requires PHP 8.2, `ext-bcmath`, `omnisense/omnitrade` (1.x), `symfony/http-client` (6.4, 7 or 8),
`omnipay/stripe` (^3.2) and `omnipay/common` (^3.0), with `nyholm/psr7`, `php-http/discovery`,
`psr/http-client` and `psr/http-factory` for the PSR-18 bridge. Omnipay requires
`symfony/http-foundation` for its own request object: installed with it, loaded only when a
payment is made, a library there with no kernel and no container. In a Symfony application with
`Omnitrade\Bridge\Symfony\OmnitradeBundle`, the factory is registered as soon as the package is
installed.

```php
$gateway = (new StripeGatewayFactory($http))->create(['api_key' => '...', 'webhook_secret' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

## Credentials

- A **secret key** from the Stripe Dashboard (Developers → API keys): `sk_test_...` in test
  mode, `sk_live_...` live. A restricted key works for what it is allowed (the catalogue's reads
  need Products and Prices).
- A **webhook endpoint** for the events below; its signing secret (`whsec_...`) is
  `webhook_secret`. Locally, `stripe listen --forward-to <your webhook URL>` prints one.
- For Connect, Connect activated on the platform's account (Dashboard → Connect), with Express
  accounts allowed in the holders' countries; for the subscription portal, the customer portal set
  up once (Billing → Customer portal).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the secret key |
| `webhook_secret` | ?string | `null` | for `notify()` | the endpoint's signing secret; without it every webhook is an `InvalidNotificationException` |
| `adaptive_pricing` | bool | `false` | no | `true`: Stripe may offer the buyer their own currency on Checkout. Always sent (`adaptive_pricing[enabled]=false` by default): the session is paid in the payment's currency |
| `payment_methods` | list of strings | `[]` | no | the Checkout methods to insist on (`['card', 'sepa_debit']`, `payment_method_types`), and what `paymentMethods()` lists; `[]`: Stripe's choice |
| `active_only` | bool | `false` | no | `true`: `fetchProducts()` lists the active products only ([catalogue](catalogue.md#stripes-catalogue)) |

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

What a `Payment` is sent as: `reference` is `client_reference_id`; `customer->email`
`customer_email`; the lines (when they and `shipping` add up to the amount, with no `discount`)
`line_items` with their name, image, unit amount and quantity, and `shipping` the session's one
shipping rate (`shipping_options[0][shipping_rate_data]`, a `fixed_amount` named "Shipping"), else
one line of the amount named by `description` (else `reference`); `returnUrl` the
`success_url`, with `session={CHECKOUT_SESSION_ID}` added to its query; `cancelUrl` the
`cancel_url` (else `returnUrl` with `cancel=1`); `idempotencyKey` the `Idempotency-Key` header;
`method` `payment_method_types` (one method, over the `payment_methods` option); `notice`
`custom_text[submit][message]` and the payment intent's `description`; `metadata`, as strings;
`locale` its first two letters; `destination` and `applicationFee` a destination charge
([Connect](connect.md#stripe-connect-and-subscriptions)). Not sent: the customer's other fields, a line's
`sku`, `reference` and `physical`, `discount` as such (the amount is one line then, below).

## Capabilities

| Request | Supported |
|---|---|
| `Purchase` | yes (a Checkout session) |
| `Authorize`, `Capture`, `VoidAuthorization` | no: Checkout takes the money when the buyer pays |
| `Refund` | yes |
| `FetchTransaction` | yes (the session) |
| `GetPaymentMethods` | yes (a list from the options) |
| `Notify` | yes |
| `FetchOrder` | no |
| `FetchProducts`, `FetchProduct` | yes (Products and Prices) |
| `FetchInventory` | no: Stripe counts no stock |
| `AffiliateLink` | no |
| `CreateAccount`, `AccountLink`, `FetchAccount` | yes (Connect) |
| `Subscribe`, `FetchSubscription`, `CancelSubscription`, `SubscriptionPortal` | yes |

## Behaviour

**Purchase.** A Checkout session in `payment` mode, answered PENDING with the session's `url` as
`redirectUrl`. A payment without `returnUrl` is a `ProviderException` before any call (`A Checkout
session needs a returnUrl: where Stripe sends the buyer back.`); an answer without an `id` or a
`url` is one too (`Stripe opened no Checkout session.`, or Stripe's error message). The session
always charges the payment's amount. The lines are listed when `Payment::linesAddUp()` and there is
no `discount`, the `shipping` on top of them as the session's shipping rate (two lines of 12.00 EUR
and 3.50 EUR of shipping: 27.50 EUR). With a `discount`, the amount is one line: Checkout's line
items take no negative amount, and its `discounts` take only a coupon or a promotion code made
beforehand ([Create a Checkout Session](https://docs.stripe.com/api/checkout/sessions/create)).

**Fetch.** The session as Stripe has it: `payment_status` `paid` or `no_payment_required` is PAID,
`status` `expired` EXPIRED, anything else PENDING with the session's `url`. The transaction's
`message` is the session's `status`, `method` its first `payment_method_types`, `metadata` its
metadata, `createdAt` its `created`. The fetch asks Stripe to expand the session's payment intent
and its latest charge (`expand[]=payment_intent.latest_charge`): a refund is read from the
charge's `amount_refunded` (or the intent's, when given), as REFUNDED, or PARTIALLY_REFUNDED when
less than the session's `amount_total`, with `refunded`.

**Refund.** The reference is the session's id (`cs_...`), a payment intent's (`pi_...`) or a
charge's (`ch_...`). From a session, the session is read for its payment intent, the intent for
its `latest_charge` (else its first charge), and the charge is refunded: with an amount through
omnipay (`POST /v1/charges/{charge}/refund`, the amount in minor units), without one in full
(`POST /v1/refunds` with the `charge` alone: Stripe refunds what is left of it,
[Create a refund](https://docs.stripe.com/api/refunds/create)); the refund's `Idempotency-Key`
is the request's key. Stripe's `succeeded` is REFUNDED, any other status PENDING; `failed` and
`canceled` are `ProviderException`s. The `reason` is not sent. The currency is the payment's (EUR
for a bare `ch_...` without an amount).

**Payment methods.** No call: the `payment_methods` option, else `card`, `apple_pay`, `google_pay`,
`link`. Labels and kinds come from a table of eleven codes (`card`; `apple_pay`, `google_pay`,
`link`, `paypal` wallets; `sepa_debit`, `bancontact`, `ideal`, `eps` banks; `klarna`,
`afterpay_clearpay` buy now pay later); another code is labelled from its name, kind `other`.
Given an amount, its currency narrows the list: `sepa_debit`, `bancontact`, `ideal` and `eps` take
euros only and are left out of an amount in another currency ([payment method
support](https://docs.stripe.com/payments/payment-methods/payment-method-support)); the country is
not read, Stripe choosing on its page by the buyer's.

**Notify.** `Stripe-Signature` is checked first: `t=<timestamp>,v1=<signature>`, the HMAC-SHA256
of `<timestamp>.<body>` with `webhook_secret`, any `v1` matching, the timestamp within 300
seconds. Then the event:

| Event | `status` | Carries |
|---|---|---|
| `checkout.session.completed`, `checkout.session.async_payment_succeeded` | PAID when the session's `payment_status` is `paid`, else PENDING | `transaction` (the session) |
| `checkout.session.expired` | EXPIRED | `transaction` |
| `checkout.session.async_payment_failed` | REFUSED | `transaction` |
| `account.updated` | - | `account` |
| `customer.subscription.created`, `.updated`, `.deleted` | - | `subscription` |
| `product.created`, `product.updated` | - | `product`, with its active prices (one call) |
| `price.created`, `price.updated`, `price.deleted` | - | `product`: the price's product read afresh (two calls); `reference` the product's id |
| `product.deleted` | - | `reference` the product's id, no product |
| any other (`invoice.paid`, `invoice.payment_failed`...) | - | `raw` |

`reference` is the event's object's id; `id` the event's. A body that is not a Stripe event is a
`ProviderException`. Subscribe the endpoint to `checkout.session.completed`,
`checkout.session.async_payment_succeeded`, `checkout.session.expired`,
`checkout.session.async_payment_failed`, and to the catalogue's, Connect's and subscriptions'
events when used.

**Calls.** omnipay sends the secret key as HTTP basic authentication; the REST calls as a bearer
token, form-encoded (`a[b][c]=...`, booleans `true`/`false`, `null` values left out), with a
30-second timeout. No token, no state kept between calls.

The catalogue, Connect and subscriptions: [Stripe's catalogue](catalogue.md#stripes-catalogue),
[Connect and subscriptions](connect.md#stripe-connect-and-subscriptions).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Stripe\StripeGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$stripe = (new StripeGatewayFactory(HttpClient::create()))->create([
    'api_key' => getenv('STRIPE_API_KEY'),                 // sk_test_...
    'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET'),
]);

$transaction = $stripe->purchase(new Payment(
    amount: Money::of(2750, 'EUR'),
    reference: 'ORDER-1042',
    customer: new Customer(email: 'camille@example.org'),
    lines: [new Line('Margaux 2019', Money::of(1200, 'EUR'), 2), new Line('Colissimo', Money::of(350, 'EUR'))],
    returnUrl: 'https://shop.example/paid',                 // Stripe comes back with ?session=cs_...
    idempotencyKey: 'ORDER-1042-attempt-1',
    locale: 'fr',
));
header('Location: '.$transaction->redirectUrl);

// on https://shop.example/paid?session=cs_...
$paid = $stripe->fetch($_GET['session'])->isPaid();

// later, part of it back
$stripe->refund($transaction->reference, Money::of(500, 'EUR'), idempotencyKey: 'ORDER-1042-refund-1');
```

The same flow on recorded answers, offline, with its output, is in the core's manual
([payments](https://github.com/glitchr-omnisense/omnitrade/blob/1.x/docs/usage-payments.md)). The
core's Docker harness calls the gateway with the keys in `docker/.env`:
`docker compose run --rm omnitrade purchase stripe --amount 1990`.

## Errors

Every failure is an `Omnitrade\Exception\ProviderException` whose `provider` is `stripe`:

- an HTTP status of 400 or more on a REST call: Stripe's `error.message`, its `error.code` in
  `providerCode` (`resource_missing`);
- a body that is not JSON: `Stripe answered HTTP <status> with a body that is not JSON.`;
- a network failure, on a REST call or through omnipay: `Stripe request failed: ...`, the
  exception as `previous`;
- omnipay's answers without what is needed: `Stripe opened no Checkout session.`, `Stripe knows no
  such session.`, `Stripe refused the refund.`, `Stripe knows no payment for this session.`,
  `Stripe knows no charge for this payment.`;
- a webhook: `InvalidNotificationException` for a missing secret, an unsigned or too old event
  (`The notification is unsigned, or too old.`) or a signature that does not match.

A missing `api_key` is an `InvalidConfigException` when the gateway is built; `Authorize`,
`Capture`, `VoidAuthorization`, `FetchOrder`, `FetchInventory` and `AffiliateLink` raise
`RequestNotSupportedException` before any call. `fetchProduct()` of an unknown id (Stripe's
`resource_missing`), or of an address, is `null`. A request omnipay refuses before sending it is a
`ProviderException` too (`Stripe request not sent: ...`).

## Verified, and not

Tested offline, 38 tests on answers shaped as Stripe's API reference gives them:
`Tests/StripeGatewayTest.php` (a Checkout session opened, a session fetched, a refund on its
charge with its idempotency key, a refund without an amount in full, a refunded session fetched REFUNDED, the shipping as Checkout's
shipping rate and a discount charged as one line, a webhook checked then read, the payment methods),
`Tests/StripeConnectTest.php` (an Express account opened then completed on Stripe's page, a
payment sent to a connected account with the platform's fee, the fee's checks, Stripe's errors, a
subscription started on Checkout, read, stopped and managed, the account and subscription events),
`Tests/StripeCatalogueTest.php` (no stock, pages, `updatedSince` among each page, search, a variant
per price, products without prices and recurring prices, addresses and unknown ids, product and
price events) and `Tests/StripeContractTest.php`, the family's conformance suite. The suite found
omnipay's network failures escaping as omnipay's exception; they now go through
`Api::sendOmnipay()`.

Not verified: no call was made to Stripe, in test mode or live; no recorded answer was captured
from a real account (the shipping rate, the expanded charge and the full refund follow Stripe's
API reference).

## Responsibility

An independent, unofficial client, not affiliated with, endorsed by or sponsored by Stripe, Inc.
Whoever runs it complies with Stripe's terms ([Stripe Services
Agreement](https://stripe.com/legal/ssa)) and obtains what they require: see the README's
Responsibility section.
