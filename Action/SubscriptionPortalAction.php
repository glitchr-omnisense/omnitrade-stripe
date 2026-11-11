<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\Request;
use Omnitrade\Request\SubscriptionPortal;
use Omnitrade\Stripe\Accounts;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;

/** The customer portal (POST /v1/billing_portal/sessions): card, invoices, cancellation. */
final class SubscriptionPortalAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof SubscriptionPortal;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof SubscriptionPortal);
        $session = $this->api->post('/v1/billing_portal/sessions', [
            'customer' => $request->customer,
            'return_url' => $request->returnUrl,
            'locale' => $request->locale ? substr($request->locale, 0, 2) : null,
        ]);
        $request->setResult(Data::cast($session['url'] ?? null));
    }
}
