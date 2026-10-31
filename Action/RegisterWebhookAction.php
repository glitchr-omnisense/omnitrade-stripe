<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\RegisterWebhook;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\WebhookEndpoints;

/**
 * Registers the shop's endpoint (POST /v1/webhook_endpoints) for the four
 * checkout.session.* events notify() reads, in the gateway's Stripe-Version,
 * and answers its signing secret - which Stripe gives at creation only. So
 * an address already registered is deleted first, then made again.
 */
final class RegisterWebhookAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof RegisterWebhook;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof RegisterWebhook);
        foreach (WebhookEndpoints::all($this->api) as $endpoint) {
            if (($endpoint['url'] ?? null) === $request->url && isset($endpoint['id'])) {
                WebhookEndpoints::delete($this->api, (string) $endpoint['id']);
            }
        }

        $request->setResult(WebhookEndpoints::webhook($this->api->post('/v1/webhook_endpoints', array_filter([
            'url' => $request->url,
            'enabled_events' => [] === $request->events ? WebhookEndpoints::EVENTS : array_values($request->events),
            'api_version' => $this->api->apiVersion(),
            'description' => 'omnitrade',
        ]))));
    }
}
