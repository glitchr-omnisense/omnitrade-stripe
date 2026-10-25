<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Refund as RefundModel;
use Omnitrade\Model\Status;
use Omnitrade\Request\Refund;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;

/**
 * Money back on what a Checkout session was paid with: its payment intent's
 * charge. The reference is the session's id (cs_...), or a payment intent's
 * (pi_...) or a charge's (ch_...) directly. The idempotency key makes the
 * same refund asked twice happen once.
 */
final class RefundAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Refund;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Refund);
        [$charge, $currency] = $this->charge($request->reference);
        $currency = $request->amount?->currency ?? $currency;

        $refund = $this->api->checkout()->refund(['transactionReference' => $charge, 'currency' => $currency]);
        if ($request->amount) {
            $refund->setAmountInteger($request->amount->amount);
        }
        if ($request->idempotencyKey) {
            $refund->setIdempotencyKeyHeader($request->idempotencyKey);
        }
        $response = $this->api->sendOmnipay($refund);
        $data = $response->getData();
        if (!$response->isSuccessful() || empty($data['id']) || \in_array($data['status'] ?? '', ['failed', 'canceled'], true)) {
            throw new ProviderException('stripe', (string) ($response->getMessage() ?? $data['failure_reason'] ?? 'Stripe refused the refund.'), $data['error']['code'] ?? null);
        }

        $request->setResult(new RefundModel(
            provider: 'stripe',
            reference: (string) $data['id'],
            amount: Money::of((int) ($data['amount'] ?? $request->amount?->amount ?? 0), strtoupper((string) ($data['currency'] ?? $currency))),
            status: 'succeeded' === ($data['status'] ?? null) ? Status::REFUNDED : Status::PENDING,
            transactionReference: $request->reference,
            message: $data['status'] ?? null,
            raw: $data,
        ));
    }

    /** @return array{string, string} the charge to refund, and its currency */
    private function charge(string $reference): array
    {
        if (str_starts_with($reference, 'ch_')) {
            return [$reference, 'EUR'];
        }
        $intent = $reference;
        $currency = 'EUR';
        if (str_starts_with($reference, 'cs_')) {
            $session = $this->api->sendOmnipay($this->api->checkout()->fetchTransaction(['transactionReference' => $reference]))->getData();
            $currency = strtoupper((string) ($session['currency'] ?? $currency));
            $intent = $session['payment_intent'] ?? null;
            if (\is_array($intent)) {
                $intent = $intent['id'] ?? null;
            }
            if (!\is_string($intent) || '' === $intent) {
                throw new ProviderException('stripe', 'Stripe knows no payment for this session.');
            }
        }
        $payment = $this->api->sendOmnipay($this->api->intents()->fetchPaymentIntent(['paymentIntentReference' => $intent]))->getData();
        $currency = strtoupper((string) ($payment['currency'] ?? $currency));
        $charge = $payment['latest_charge'] ?? $payment['charges']['data'][0]['id'] ?? null;
        if (\is_array($charge)) {
            $charge = $charge['id'] ?? null;
        }
        if (!\is_string($charge) || '' === $charge) {
            throw new ProviderException('stripe', 'Stripe knows no charge for this payment.');
        }

        return [$charge, $currency];
    }
}
