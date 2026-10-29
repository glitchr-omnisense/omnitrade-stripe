# Changelog

## Unreleased (1.x)

### Fixed
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
