<?php

namespace Omnitrade\Stripe;

use Omnipay\Stripe\CheckoutGateway as OmnipayCheckoutGateway;

/** omnipay/stripe's Checkout gateway, its purchase() and refund() our requests (CheckoutPurchaseRequest, RefundRequest). */
class CheckoutGateway extends OmnipayCheckoutGateway
{
    /** @param array<string, mixed> $parameters */
    public function purchase(array $parameters = []): CheckoutPurchaseRequest
    {
        $request = $this->createRequest(CheckoutPurchaseRequest::class, $parameters);
        \assert($request instanceof CheckoutPurchaseRequest);

        return $request;
    }

    /** @param array<string, mixed> $parameters */
    public function refund(array $parameters = []): RefundRequest
    {
        $request = $this->createRequest(RefundRequest::class, $parameters);
        \assert($request instanceof RefundRequest);

        return $request;
    }
}
