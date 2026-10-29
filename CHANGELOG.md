# Changelog

## Unreleased (1.x)

### Fixed
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
