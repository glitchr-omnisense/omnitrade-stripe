<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\AccountLink;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Accounts;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;

/** The onboarding page of a connected account (POST /v1/account_links): single use, valid a few minutes. */
final class AccountLinkAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof AccountLink;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof AccountLink);
        $link = $this->api->post('/v1/account_links', [
            'account' => $request->reference,
            'return_url' => $request->returnUrl,
            'refresh_url' => $request->refreshUrl,
            'type' => AccountLink::UPDATE === $request->type ? 'account_update' : 'account_onboarding',
        ]);
        $request->setResult(Data::cast($link['url'] ?? null));
    }
}
