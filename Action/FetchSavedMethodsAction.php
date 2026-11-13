<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\Intents;

/**
 * The payment methods attached to a Stripe customer, newest first (Stripe's order): the cards,
 * and the other types the gateway saves (its payment_methods option) - Stripe lists one type a
 * call, as the API version this code runs on asks for a type.
 */
final class FetchSavedMethodsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** @param string[] $paymentMethods the gateway's payment_methods option; card when none */
    public function __construct(private readonly array $paymentMethods = [])
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchSavedMethods;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchSavedMethods);
        $methods = [];
        foreach (array_values(array_unique($this->paymentMethods ?: ['card'])) as $type) {
            $page = $this->api->get('/v1/payment_methods', ['customer' => $request->customer, 'type' => $type, 'limit' => 100]);
            foreach (Data::maps($page['data'] ?? []) as $method) {
                $methods[] = Intents::savedMethod($method);
            }
        }

        $request->setResult($methods);
    }
}
