<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;

/**
 * A payment method detached from its Stripe customer: it can no longer be charged. Read first,
 * and detached only when it is the request's customer's - a reference sent by a client never
 * detaches another customer's card (a ProviderException, code "not_this_customer").
 */
final class RemoveSavedMethodAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof RemoveSavedMethod;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof RemoveSavedMethod);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $request->reference)) {
            throw new ProviderException('stripe', 'Not a payment method\'s id.', 'not_this_customer');
        }
        $method = Data::of($this->api->get('/v1/payment_methods/'.$request->reference));
        $owner = $method->textOrNull('customer') ?? $method->textOrNull('customer.id');
        if ($owner !== $request->customer) {
            throw new ProviderException('stripe', 'This payment method is not the customer\'s.', 'not_this_customer');
        }
        $this->api->post('/v1/payment_methods/'.$request->reference.'/detach');

        $request->setResult(true);
    }
}
