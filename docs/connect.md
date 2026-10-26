---
title: Connect and subscriptions
order: 30
---

# Stripe: Connect and subscriptions

The contract is omnisense/omnitrade's ([connected accounts](https://github.com/glitchr-omnisense/omnitrade/blob/1.x/docs/connect.md),
[subscriptions](https://github.com/glitchr-omnisense/omnitrade/blob/1.x/docs/subscriptions.md)); this is what Stripe is asked.

## Connect

The platform's own secret key does everything: no `Stripe-Account` header, no
OAuth. Connect must be activated on the platform's Stripe account (dashboard →
Connect), with Express accounts allowed in the holders' countries.

| Request | Call | Notes |
|---|---|---|
| `CreateAccount` | `POST /v1/accounts` | `type=express`, `country`, `email`, `business_type`, `capabilities[<name>][requested]=true` (`transfers` by default), `metadata`; the request's idempotency key as `Idempotency-Key` |
| `AccountLink` | `POST /v1/account_links` | `type=account_onboarding` (or `account_update`), `return_url`, `refresh_url` |
| `FetchAccount` | `GET /v1/accounts/{id}` | `charges_enabled`, `payouts_enabled`, `details_submitted`, `requirements.currently_due` + `past_due` |

A `Payment` with a `destination` makes the Checkout session a **destination
charge**: `payment_intent_data[transfer_data][destination]` and, with an
`applicationFee`, `payment_intent_data[application_fee_amount]`. The charge is
the platform's (its name on the statement, its Stripe fees, its disputes); the
amount less the fee is transferred to the connected account at once. A refund
through `refund()` refunds the charge; it does not reverse the transfer nor
give the fee back (do those in Stripe's dashboard when needed).

In some countries a destination charge needs the `card_payments` capability
with `transfers`: pass `capabilities: ['transfers', 'card_payments']`.

## Subscriptions

| Request | Call |
|---|---|
| `Subscribe` | `POST /v1/checkout/sessions`, `mode=subscription`; one line: the request's `price`, or `price_data` (the payment's amount, `recurring[interval]`, `[interval_count]`, the description as the product's name); `subscription_data[metadata]`, `[trial_period_days]`; `customer` or `customer_email` |
| `FetchSubscription` | `GET /v1/subscriptions/{id}` |
| `CancelSubscription` | `POST /v1/subscriptions/{id}` with `cancel_at_period_end=true`, or `DELETE` to stop at once |
| `SubscriptionPortal` | `POST /v1/billing_portal/sessions` (`customer`, `return_url`, `locale`): the portal must be set up once in the dashboard (Billing → Customer portal) |

The period's dates are read from the subscription, or from its first item
(where Stripe's newer API versions keep them).

## What is mapped how

`Model\Account`, from Stripe's account:

| Stripe | omnitrade |
|---|---|
| `id` (`acct_...`) | `reference` |
| `type` | `type` (`express` when absent) |
| `country`, `email` | `country`, `email` |
| `details_submitted`, `charges_enabled`, `payouts_enabled` | `detailsSubmitted`, `chargesEnabled`, `payoutsEnabled` (`isReady()`: both enabled) |
| `requirements.currently_due` + `requirements.past_due` | `requirements`, once each |
| `default_currency` | `defaultCurrency`, upper-cased |
| `metadata` | `metadata` |

`Model\Subscription`, from Stripe's subscription:

| Stripe | omnitrade |
|---|---|
| `id` (`sub_...`) | `reference` |
| `status` `active`, `trialing`, `past_due`, `unpaid`, `paused` | the same constant |
| `status` `canceled`, `incomplete_expired` | `Subscription::CANCELLED` |
| any other `status` (`incomplete`) | `Subscription::INCOMPLETE` |
| `customer` (an id, or an expanded customer's `id`) | `customer` |
| the first item's price `unit_amount` times its `quantity`, its `currency` | `amount` |
| the first item's price `recurring.interval`, `recurring.interval_count` | `interval`, `intervalCount` |
| `current_period_start`, `current_period_end` (else the first item's) | `currentPeriodStart`, `currentPeriodEnd` |
| `cancel_at_period_end`, `canceled_at` | `cancelAtPeriodEnd`, `cancelledAt` |
| the first item's price `id` | `price` |
| `metadata` | `metadata` |

`Subscribe` also sends `client_reference_id` (the payment's reference), `locale` (its first two
letters), the payment's `metadata` on the session and in `subscription_data[metadata]`, the
payment's description as `subscription_data[description]`, and the request's `customer` (then no
`customer_email`) or the payment's customer's email. A payment without `returnUrl` is a
`ProviderException` before any call; the success and cancel addresses are made as for a purchase
(`?session={CHECKOUT_SESSION_ID}`, `?cancel=1`). The session is answered PENDING with its `url`.
The payment's lines, `method`, `notice`, `destination` and Stripe's `payment_methods` and
`adaptive_pricing` options are not sent for a subscription.

## Events

Forward these to the gateway's webhook, the Connect ones included
(`stripe listen --forward-to … --forward-connect-to …`):

| Event | `Notification` |
|---|---|
| `account.updated` | `$account`, `$reference` = `acct_…` |
| `checkout.session.completed` of a subscription | `$transaction` PAID; `raw['subscription']`, `raw['customer']` |
| `customer.subscription.created` / `.updated` / `.deleted` | `$subscription`, `$reference` = `sub_…` |
| `invoice.paid`, `invoice.payment_failed` | no status: read `$raw` (the subscription's own events say what changed) |

Not verified against Stripe's live API here: the package's tests run on
recorded answers (`Tests/StripeConnectTest.php`).
