<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\Intents;
use Omnitrade\Stripe\Sessions;

/**
 * The Checkout session as Stripe has it now: paid, still open, expired, or
 * refunded - its payment intent and that intent's latest charge expanded, the
 * charge's amount_refunded what says so (unexpanded, Stripe gives the intent's
 * id only). A payment intent's id (pi_..., a saved method's charge) is read as
 * that intent (Intents::transaction()).
 */
final class FetchTransactionAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransaction;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransaction);
        if (str_starts_with($request->reference, 'pi_')) {
            // A saved method's charge (Payment::$savedMethod): the payment intent itself.
            $request->setResult(Intents::transaction($this->api->get('/v1/payment_intents/'.$request->reference)));

            return;
        }
        $data = Data::map($this->api->sendOmnipay($this->api->checkout()->fetchTransaction(['transactionReference' => $request->reference, 'expand' => ['payment_intent.latest_charge']]))->getData());
        if (empty($data['id'])) {
            $error = Data::of($data)->data('error');

            throw new ProviderException('stripe', $error->textOrNull('message') ?? 'Stripe knows no such session.', $error->textOrNull('code'));
        }
        $request->setResult(Sessions::transaction($data));
    }
}
