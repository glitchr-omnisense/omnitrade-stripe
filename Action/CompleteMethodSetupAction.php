<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Request\CompleteMethodSetup;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\Intents;

/**
 * The method a Checkout session in setup mode saved, read once the buyer is back: Stripe has
 * nothing left to do (the session's SetupIntent attached its PaymentMethod to the customer when
 * the buyer confirmed), so this only reads GET /v1/checkout/sessions/{id} with
 * setup_intent.payment_method expanded and answers that method - asked twice, the same one,
 * nothing sent but reads.
 *
 * The session must be the request's customer's (not_this_customer otherwise, an id that is not a
 * session's too), complete, and its SetupIntent succeeded: an open session (the buyer still on
 * the page, or gone back), an expired one, or a SetupIntent still asking for its method or the
 * bank's check is setup_not_completed.
 */
final class CompleteMethodSetupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CompleteMethodSetup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof CompleteMethodSetup);
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $request->reference)) {
            throw new ProviderException('stripe', 'Not a Checkout session\'s id.', CompleteMethodSetup::NOT_THIS_CUSTOMER);
        }
        $session = Data::of($this->api->get('/v1/checkout/sessions/'.$request->reference, ['expand' => ['setup_intent.payment_method']]));
        $owner = $session->textOrNull('customer.id') ?? $session->textOrNull('customer');
        if ('setup' !== $session->textOrNull('mode') || (null !== $request->customer && '' !== $request->customer && $owner !== $request->customer)) {
            throw new ProviderException('stripe', 'This setup is not the customer\'s.', CompleteMethodSetup::NOT_THIS_CUSTOMER);
        }
        $status = $session->textOrNull('status') ?? 'unknown';
        $intent = $session->data('setup_intent');
        $intentStatus = $intent->textOrNull('status') ?? 'unknown';
        if ('complete' !== $status || 'succeeded' !== $intentStatus) {
            throw new ProviderException('stripe', sprintf('The setup is not completed (session %s, setup intent %s): the buyer has not saved a method.', $status, $intentStatus), CompleteMethodSetup::NOT_COMPLETED);
        }
        $method = Data::map($intent->get('payment_method'));
        if ('' === Data::of($method)->text('id')) {
            throw new ProviderException('stripe', 'The setup saved no payment method.', CompleteMethodSetup::NOT_COMPLETED);
        }
        // Expanded through the session, the method names its customer; the session's otherwise.
        $method['customer'] ??= $owner;

        $request->setResult(Intents::savedMethod($method));
    }
}
