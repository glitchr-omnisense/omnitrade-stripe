<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Sessions;

/** The Checkout session as Stripe has it now: paid, still open, expired. */
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
        $data = $this->api->sendOmnipay($this->api->checkout()->fetchTransaction(['transactionReference' => $request->reference]))->getData();
        if (empty($data['id'])) {
            throw new ProviderException('stripe', (string) ($data['error']['message'] ?? 'Stripe knows no such session.'), $data['error']['code'] ?? null);
        }
        $request->setResult(Sessions::transaction($data));
    }
}
