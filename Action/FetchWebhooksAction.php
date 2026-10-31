<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\FetchWebhooks;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\WebhookEndpoints;

/** The account's webhook endpoints (GET /v1/webhook_endpoints, every page), without secrets. */
final class FetchWebhooksAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchWebhooks;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchWebhooks);
        $request->setResult(array_map(WebhookEndpoints::webhook(...), WebhookEndpoints::all($this->api)));
    }
}
