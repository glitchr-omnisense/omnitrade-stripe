<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Refund as RefundModel;
use Omnitrade\Model\Status;
use Omnitrade\Request\Refund;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;

/**
 * Money back on what a Checkout session was paid with: its payment intent's
 * charge. The reference is the session's id (cs_...), or a payment intent's
 * (pi_...) or a charge's (ch_...) directly. The idempotency key makes the
 * same refund asked twice happen once. Without an amount, the whole charge
 * (what is left of it) is refunded. The gateway's tags (the "tags" option:
 * site) are the refund's metadata.
 */
final class RefundAction implements ActionInterface, ApiAwareInterface, TagsAwareInterface
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
        return $request instanceof Refund;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Refund);
        [$charge, $currency] = $this->charge($request->reference);
        $metadata = $this->tags()->merge() ?: null;
        $currency = $request->amount->currency ?? $currency;

        if (null === $request->amount) {
            // The whole charge: Stripe's Create a refund with no amount (omnipay's refund requires one).
            $data = $this->api->post('/v1/refunds', ['charge' => $charge, 'metadata' => $metadata], $request->idempotencyKey);
            if (empty($data['id']) || \in_array($data['status'] ?? '', ['failed', 'canceled'], true)) {
                throw new ProviderException('stripe', Data::cast($data['failure_reason'] ?? 'Stripe refused the refund.'));
            }
        } else {
            $refund = $this->api->checkout()->refund(['transactionReference' => $charge, 'currency' => $currency]);
            $refund->setAmountInteger($request->amount->amount);
            $refund->setMetadata($metadata);
            if ($request->idempotencyKey) {
                $refund->setIdempotencyKeyHeader($request->idempotencyKey);
            }
            $response = $this->api->sendOmnipay($refund);
            $data = Data::map($response->getData());
            if (!$response->isSuccessful() || empty($data['id']) || \in_array($data['status'] ?? '', ['failed', 'canceled'], true)) {
                throw new ProviderException('stripe', Data::cast($response->getMessage() ?? $data['failure_reason'] ?? 'Stripe refused the refund.'), Data::of($data)->textOrNull('error.code'));
            }
        }

        $request->setResult(new RefundModel(
            provider: 'stripe',
            reference: Data::cast($data['id']),
            amount: Money::of(Data::integer($data['amount'] ?? $request->amount->amount ?? 0), strtoupper(Data::cast($data['currency'] ?? $currency))),
            status: 'succeeded' === ($data['status'] ?? null) ? Status::REFUNDED : Status::PENDING,
            transactionReference: $request->reference,
            message: Data::of($data)->textOrNull('status'),
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
            $session = Data::map($this->api->sendOmnipay($this->api->checkout()->fetchTransaction(['transactionReference' => $reference]))->getData());
            $currency = strtoupper(Data::cast($session['currency'] ?? $currency));
            $intent = $session['payment_intent'] ?? null;
            if (\is_array($intent)) {
                $intent = $intent['id'] ?? null;
            }
            if (!\is_string($intent) || '' === $intent) {
                throw new ProviderException('stripe', 'Stripe knows no payment for this session.');
            }
        }
        $payment = Data::map($this->api->sendOmnipay($this->api->intents()->fetchPaymentIntent(['paymentIntentReference' => $intent]))->getData());
        $currency = strtoupper(Data::cast($payment['currency'] ?? $currency));
        $charge = $payment['latest_charge'] ?? Data::of($payment)->get('charges.data.0.id');
        if (\is_array($charge)) {
            $charge = $charge['id'] ?? null;
        }
        if (!\is_string($charge) || '' === $charge) {
            throw new ProviderException('stripe', 'Stripe knows no charge for this payment.');
        }

        return [$charge, $currency];
    }
}
