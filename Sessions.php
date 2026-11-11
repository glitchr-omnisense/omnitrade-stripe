<?php

namespace Omnitrade\Stripe;

use Omnitrade\Model\Money;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;

/** A Checkout session, as Stripe answers it, read as a Transaction. */
final class Sessions
{
    /** @param array<string, mixed> $session */
    public static function transaction(array $session): Transaction
    {
        $status = match (true) {
            'paid' === ($session['payment_status'] ?? null), 'no_payment_required' === ($session['payment_status'] ?? null) => Status::PAID,
            'expired' === ($session['status'] ?? null) => Status::EXPIRED,
            default => Status::PENDING,
        };
        $read = Data::of($session);
        $intent = $session['payment_intent'] ?? null;
        $refunded = \is_array($intent) ? ($read->get('payment_intent.amount_refunded') ?? $read->get('payment_intent.latest_charge.amount_refunded')) : null;
        if (Status::PAID === $status && \is_int($refunded) && $refunded > 0) {
            $status = $refunded >= $read->number('amount_total') ? Status::REFUNDED : Status::PARTIALLY_REFUNDED;
        }
        $currency = strtoupper(Data::cast($session['currency'] ?? 'EUR'));

        return new Transaction(
            provider: 'stripe',
            reference: $read->text('id'),
            status: $status,
            amount: $read->has('amount_total') ? Money::of($read->number('amount_total'), $currency) : null,
            redirectUrl: Status::PENDING === $status ? $read->textOrNull('url') : null,
            message: $read->textOrNull('status'),
            method: \is_array($read->get('payment_method_types')) ? $read->text('payment_method_types.0') : null,
            refunded: \is_int($refunded) && $refunded > 0 ? Money::of($refunded, $currency) : null,
            metadata: $read->fields('metadata'),
            createdAt: $read->has('created') ? (new \DateTimeImmutable())->setTimestamp($read->number('created')) : null,
            raw: $session,
        );
    }
}
