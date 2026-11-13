<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\MethodSetup;
use Omnitrade\Request\Request;
use Omnitrade\Request\SetupMethod;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;

/**
 * A payment method saved on Stripe's own page: Checkout in setup mode, for a Stripe customer -
 * the request's ($customer->reference, cus_...), else one made from its e-mail and name. Once the
 * buyer is through, Checkout has attached the method to that customer: FetchSavedMethods lists
 * it, a Payment naming it (savedMethod) charges it. The card number never reaches the site.
 *
 * The idempotency key is the session's, and with ":customer" the customer's, so that the same
 * setup asked twice opens one page for one customer. The gateway's tags (site) and the request's
 * metadata are the customer's and the session's metadata.
 */
final class SetupMethodAction implements ActionInterface, ApiAwareInterface, TagsAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;
    use TagsAwareTrait;

    /** @param string[] $paymentMethods the gateway's payment_methods option: what to offer to save, card when none */
    public function __construct(private readonly array $paymentMethods = [])
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof SetupMethod;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof SetupMethod);
        $metadata = $this->tags()->merge($request->metadata) ?: null;
        $customer = $request->customer->reference;
        if (null === $customer || '' === $customer) {
            $made = $this->api->post('/v1/customers', [
                'email' => $request->customer->email,
                'name' => $request->customer->name,
                'phone' => $request->customer->phone,
                'metadata' => $metadata,
            ], null !== $request->idempotencyKey ? $request->idempotencyKey.':customer' : null);
            $customer = Data::of($made)->textOrNull('id') ?? throw new ProviderException('stripe', 'Stripe made no customer.');
        }

        $session = $this->api->post('/v1/checkout/sessions', [
            'mode' => 'setup',
            'customer' => $customer,
            'payment_method_types' => $request->method ? [$request->method] : ($this->paymentMethods ?: ['card']),
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl ?? $request->returnUrl,
            'locale' => $request->locale ? substr($request->locale, 0, 2) : null,
            'metadata' => $metadata,
            'setup_intent_data' => null !== $metadata ? ['metadata' => $metadata] : null,
        ], $request->idempotencyKey);
        $read = Data::of($session);
        $id = $read->textOrNull('id');
        $url = $read->textOrNull('url');
        if (null === $id || null === $url) {
            throw new ProviderException('stripe', 'Stripe opened no setup page.');
        }

        $request->setResult(new MethodSetup('stripe', $id, $url, $customer, $session));
    }
}
