<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Request\Request;
use Omnitrade\Request\Subscribe;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Sessions;

/**
 * A Checkout session in subscription mode (POST /v1/checkout/sessions): the
 * buyer is sent to Stripe's page (PENDING with its URL), pays the first
 * period, and Stripe renews. The plan is the request's Stripe price when
 * given, else a price made on the fly from the payment (its amount every
 * interval, named by its description). The payment's metadata is put on the
 * session and on the subscription (subscription_data.metadata), with the
 * gateway's tags (the "tags" option: site) under it, so every later event
 * carries them.
 *
 * Then: checkout.session.completed tells of the first payment (the session's
 * "subscription" is the subscription's id), customer.subscription.updated and
 * .deleted of its life, invoice.paid of each renewal.
 */
final class SubscribeAction implements ActionInterface, ApiAwareInterface, TagsAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;
    use TagsAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Subscribe;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Subscribe);
        $payment = $request->payment;
        if (null === $payment->returnUrl) {
            throw new ProviderException('stripe', 'A Checkout session needs a returnUrl: where Stripe sends the buyer back.');
        }
        $metadata = $this->tags()->merge($payment->metadata) ?: null;
        $line = null !== $request->price
            ? ['price' => $request->price, 'quantity' => 1]
            : ['quantity' => 1, 'price_data' => [
                'currency' => strtolower($payment->amount->currency),
                'unit_amount' => $payment->amount->amount,
                'recurring' => ['interval' => $request->interval, 'interval_count' => $request->intervalCount],
                'product_data' => ['name' => $payment->description ?? $payment->reference],
            ]];
        $join = static fn (string $url, array $query) => $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);

        $session = $this->api->post('/v1/checkout/sessions', [
            'mode' => 'subscription',
            'line_items' => [$line],
            'customer' => $request->customer,
            'customer_email' => $request->customer ? null : $payment->customer?->email,
            'client_reference_id' => $payment->reference,
            'locale' => $payment->locale ? substr($payment->locale, 0, 2) : null,
            'metadata' => $metadata,
            'subscription_data' => array_filter(['metadata' => $metadata, 'trial_period_days' => $request->trialDays, 'description' => $payment->description]),
            'success_url' => str_replace('SESSION_ID_PLACEHOLDER', '{CHECKOUT_SESSION_ID}', $join($payment->returnUrl, ['session' => 'SESSION_ID_PLACEHOLDER'])),
            'cancel_url' => $payment->cancelUrl ?? $join($payment->returnUrl, ['cancel' => '1']),
        ], $payment->idempotencyKey);
        if (empty($session['id']) || empty($session['url'])) {
            throw new ProviderException('stripe', 'Stripe opened no Checkout session.');
        }

        $request->setResult(Sessions::transaction($session));
    }
}
