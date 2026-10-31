<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\DeleteWebhook;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\WebhookEndpoints;

/** Removes a webhook endpoint (DELETE /v1/webhook_endpoints/{id}); true also when it was already gone. */
final class DeleteWebhookAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof DeleteWebhook;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof DeleteWebhook);
        $request->setResult(WebhookEndpoints::delete($this->api, $request->id));
    }
}
