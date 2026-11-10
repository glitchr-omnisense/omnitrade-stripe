<?php

namespace Omnitrade\Stripe;

use Omnipay\Stripe\CheckoutGateway as OmnipayCheckoutGateway;

/** omnipay/stripe's Checkout gateway, its purchase() and refund() our requests (CheckoutPurchaseRequest, RefundRequest). */
class CheckoutGateway extends OmnipayCheckoutGateway
{
    public function purchase(array $parameters = []): CheckoutPurchaseRequest
    {
        return $this->createRequest(CheckoutPurchaseRequest::class, $parameters);
    }

    public function refund(array $parameters = []): RefundRequest
    {
        return $this->createRequest(RefundRequest::class, $parameters);
    }
}
