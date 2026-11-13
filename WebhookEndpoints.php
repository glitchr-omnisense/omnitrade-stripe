<?php

namespace Omnitrade\Stripe;

use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Webhook;

/**
 * Stripe's webhook endpoints (/v1/webhook_endpoints) read as Webhooks, and
 * the events the gateway's notify() reads.
 */
final class WebhookEndpoints
{
    /** What NotifyAction turns into a status: the endpoint registers these when asked for no event. */
    public const EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        // A saved method's charge (Payment::$savedMethod, 1.1): paid, or refused after the bank's check.
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
    ];

    /** @return list<array<string, mixed>> every endpoint of the account, page after page */
    public static function all(Api $api): array
    {
        $endpoints = [];
        $after = null;
        do {
            $page = $api->get('/v1/webhook_endpoints', array_filter(['limit' => 100, 'starting_after' => $after]));
            $data = Data::maps((array) ($page['data'] ?? []));
            $endpoints = [...$endpoints, ...$data];
            $after = [] === $data ? null : ($data[\count($data) - 1]['id'] ?? null);
        } while (true === ($page['has_more'] ?? false) && null !== $after);

        return $endpoints;
    }

    /** true, also when Stripe no longer knows the endpoint */
    public static function delete(Api $api, string $id): bool
    {
        try {
            $api->delete('/v1/webhook_endpoints/'.rawurlencode($id));
        } catch (ProviderException $e) {
            if ('resource_missing' !== $e->providerCode) {
                throw $e;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $endpoint */
    public static function webhook(array $endpoint): Webhook
    {
        $raw = $endpoint;
        unset($raw['secret']);

        return new Webhook(
            Data::cast($endpoint['id'] ?? ''),
            Data::cast($endpoint['url'] ?? ''),
            Data::texts($endpoint['enabled_events'] ?? []),
            isset($endpoint['secret']) && '' !== $endpoint['secret'] ? Data::cast($endpoint['secret']) : null,
            $raw,
        );
    }
}
