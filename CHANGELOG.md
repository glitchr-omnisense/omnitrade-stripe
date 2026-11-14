# Changelog

## [Unreleased]

## [1.2.0] - 2026-11-14

omnisense/omnitrade 1.2's completion of a saved method's setup. Additive: nothing that worked before
changes.

### Added
- `CompleteMethodSetup` (`MethodSetup::$reference`, the `cs_...` of a Checkout session in setup
  mode, and its customer): the session read with its SetupIntent's method expanded (`GET
  /v1/checkout/sessions/{id}?expand[]=setup_intent.payment_method`) and that method answered as a
  `SavedMethod` - Checkout already attached it to the customer, so only reads are sent and the same
  method comes back when asked twice. Another customer's session, a session not in setup mode or
  an id that is not a session's: `not_this_customer`; a session still open or expired, or its
  SetupIntent not `succeeded`: `setup_not_completed`; an unknown session: Stripe's
  `resource_missing`. Listed as `completeMethodSetup` by `capabilities()`.
- Tests: `StripeSavedMethodsTest` (8), the contract suite's setup and its completion on recorded
  answers (`StripeContractTest`).

### Changed
- Requires `omnisense/omnitrade` `^1.2` (`Request\CompleteMethodSetup`).

## [1.1.0] - 2026-11-13

Saved payment methods and their off-session charge. Additive: nothing that worked before changes,
except that `payment_intent.*` events now carry a status (they had none).

### Added
- `SetupMethod`: Checkout in `setup` mode for a Stripe customer, made first (`POST /v1/customers`,
  tagged) when the request names none; answered as a `MethodSetup` (the page, the customer).
- `FetchSavedMethods`: the customer's PaymentMethods, one call per type saved (`card` by default),
  as `SavedMethod`s ("Visa •••• 4242", brand, last four digits, expiry).
- `RemoveSavedMethod`: the method read, then detached only when it is that customer's
  (`not_this_customer` otherwise, nothing detached).
- `Purchase` of a `Payment` with `savedMethod`: a PaymentIntent confirmed off-session with the
  payment's idempotency key; a card refused is a REFUSED transaction with Stripe's decline code; the
  bank's 3-D Secure check confirms it again on-session with `returnUrl`, PENDING with the bank's
  page as `redirectUrl` (key `:confirm`).
- `fetch('pi_...')` reads a payment intent (`Intents::transaction()`).
- `notify()`: `payment_intent.succeeded` PAID, `payment_intent.payment_failed` REFUSED (PENDING when its
  error is `authentication_required`: Stripe sends it for the off-session attempt while the buyer is
  sent to the bank's page), `payment_intent.canceled` CANCELLED, the intent as the transaction.
- `Api::attempt()`: a POST whose HTTP 402 (a card's refusal) is answered as Stripe's error body.
- Tests: `StripeSavedMethodsTest` (6), on answers shaped as Stripe's API reference.

### Changed
- `RegisterWebhook` without events (`WebhookEndpoints::EVENTS`) also subscribes the endpoint to
  `payment_intent.succeeded` and `payment_intent.payment_failed`. An endpoint registered before
  keeps its events until registered again.
- Requires `omnisense/omnitrade` `^1.1` (the requests, `SavedMethod`, `MethodSetup`,
  `Payment::$savedMethod`).

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0` and `omnisense/omnitrade` `^1.0`, stable versions, where it
  required `@dev` ones; `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- README: the install line and a first call on a recorded answer (no account, nothing sent).

### Added
- `policy.json` (`extra.omnisense.policy`): 25 calls a second, 5 Connect account creations a second (the
  sandbox's, stricter than live mode's 100 and 30), the other limits in its notes; verified 2026-11-10.
- The gateway's Backoff (omnisense/omnitrade's `Http\Throttle`): no call while Stripe's wait lasts; a
  429, a rate limit or a `lock_timeout` (through omnipay too, read from the error code), is a
  `RateLimitedException`, never retried. Requires omnisense/contracts.
- The gateway's `tags` option (omnisense/omnitrade): `metadata` on the Checkout Session and on its
  payment intent (`payment_intent_data.metadata`, which now also carries the payment's own
  metadata), on a subscription's (`subscription_data.metadata`), on refunds (`RefundRequest`, omnipay's
  `/v1/charges/{charge}/refund` with the `metadata` it did not send, and `/v1/refunds`), on connected
  accounts and on registered webhook endpoints. `notify()` gives the event object's metadata back as
  `Notification::$tags` (an invoice's: its subscription's). Parameters checked against Stripe's
  OpenAPI description. Not tagged: the customer Checkout creates (no parameter sets its metadata).
  Tests on recorded requests (`StripeTagsTest`).
- The webhook endpoint registered from the application: `RegisterWebhook` (lists
  `/v1/webhook_endpoints`, deletes the endpoints on the same address, makes one for the four
  `checkout.session.*` events in the gateway's `api_version`, answers its `whsec_` secret),
  `FetchWebhooks`, `DeleteWebhook` (true when already gone); `WebhookEndpoints::EVENTS`,
  `Api::apiVersion()`. Tests on answers shaped as Stripe's (`StripeWebhookEndpointsTest`).

### Changed
- PHPStan at level max with an empty baseline (339 errors that predated it): Stripe's answers read
  through `Data` (internal: omnisense/contracts' `Http\Data` with reads that keep PHP's `(string)` and
  `(int)` of a scalar), the arrays typed. Types only: no signature changed, no behaviour either.

### Fixed
- Every call names its API version (`Stripe-Version`, the new `api_version` option, `2020-08-27` by
  default, `Api::API_VERSION`): the Checkout, the refunds and fetches through omnipay/stripe, and the
  plain calls (catalogue, Connect, subscriptions). Without it Stripe used the account's default, the
  version current when the account was made: a sandbox made after 2026-10-28 (`2026-10-28.endive`)
  answered every purchase with "The `payment_method_types` parameter is no longer supported", while
  older accounts, on `2020-08-27`, worked. `null` keeps the account's default.
- A `ProviderException` no longer carries the secret key. The message quoted the HTTP client's (the
  whole URL), the previous was the client's own exception, and every trace of the chain kept the
  arguments of the calls under it (the request's options, the headers with the credentials) when
  `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The message masks them
  as `***`, the previous is a copy of the client's exception (the same class and code), and no
  exception `Api` throws keeps its trace's arguments (`Omnitrade\Http\Redaction`).
- `paymentMethods($for)` reads the amount's currency: SEPA Direct Debit, Bancontact, iDEAL and
  EPS, which take euros only, are left out of an amount in another currency.
- `refund($reference)` without an amount refunds the whole charge (`POST /v1/refunds` with the
  charge alone), where omnipay's `InvalidRequestException` ("The amount parameter is required")
  escaped before any call. Any request omnipay refuses before sending it is a `ProviderException`
  now (`Api::sendOmnipay()`).
- A Checkout session charges the payment's amount when its lines add up to it only with
  `Payment::$shipping` or `::$discount`: the shipping is sent as the session's shipping rate
  (`shipping_options[0][shipping_rate_data]`, a fixed amount) on top of the listed lines, and a
  payment with a discount is charged as one line of its amount. The lines alone were sent before,
  and the session was for their total (24.00 in place of 27.50).
- `fetch()` reads a refunded session REFUNDED (or PARTIALLY_REFUNDED), not PAID: it asks Stripe to
  expand the session's payment intent and its latest charge (`expand[]=payment_intent.latest_charge`),
  whose `amount_refunded` says what was refunded.
