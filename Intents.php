<?php

namespace Omnitrade\Stripe;

use Omnitrade\Model\Money;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\SavedMethod;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;

/**
 * Stripe's PaymentIntents and PaymentMethods, as Stripe answers them, read as Omnitrade's
 * models: an intent charged with a saved method (off-session) as a Transaction, a card kept for a
 * customer as a SavedMethod.
 */
final class Intents
{
    /**
     * A PaymentIntent: succeeded is PAID (REFUNDED / PARTIALLY_REFUNDED once its charge was given
     * back), requires_capture AUTHORIZED, canceled CANCELLED, requires_payment_method after an
     * attempt (last_payment_error) REFUSED - PENDING when that error is only the bank asking for
     * its buyer (authentication_required); the rest - requires_action, requires_confirmation,
     * processing - PENDING, with the page of the bank's check (next_action.redirect_to_url) as
     * the redirectUrl when Stripe gives one.
     *
     * @param array<string, mixed> $intent
     */
    public static function transaction(array $intent): Transaction
    {
        $read = Data::of($intent);
        $error = $read->data('last_payment_error');
        $status = match ($read->textOrNull('status')) {
            'succeeded' => Status::PAID,
            'requires_capture' => Status::AUTHORIZED,
            'canceled' => Status::CANCELLED,
            // Refused, unless the bank only asked for its buyer: the buyer may still confirm on its page.
            'requires_payment_method' => ($error->has('code') || $error->has('message')) && !self::needsTheBuyer($error) ? Status::REFUSED : Status::PENDING,
            default => Status::PENDING,
        };
        $currency = strtoupper(Data::cast($intent['currency'] ?? 'EUR'));
        $refunded = $read->get('latest_charge.amount_refunded') ?? $read->get('charges.data.0.amount_refunded');
        if (Status::PAID === $status && \is_int($refunded) && $refunded > 0) {
            $status = $refunded >= $read->number('amount') ? Status::REFUNDED : Status::PARTIALLY_REFUNDED;
        }
        $redirect = 'redirect_to_url' === $read->textOrNull('next_action.type') ? $read->textOrNull('next_action.redirect_to_url.url') : null;

        return new Transaction(
            provider: 'stripe',
            reference: $read->text('id'),
            status: $status,
            amount: $read->has('amount') ? Money::of($read->number('amount'), $currency) : null,
            redirectUrl: Status::PENDING === $status ? $redirect : null,
            message: $error->textOrNull('message') ?? $read->textOrNull('status'),
            providerCode: $error->textOrNull('decline_code') ?? $error->textOrNull('code'),
            method: \is_array($read->get('payment_method_types')) ? $read->text('payment_method_types.0') : null,
            refunded: \is_int($refunded) && $refunded > 0 ? Money::of($refunded, $currency) : null,
            metadata: $read->fields('metadata'),
            createdAt: $read->has('created') ? (new \DateTimeImmutable())->setTimestamp($read->number('created')) : null,
            raw: $intent,
        );
    }

    /** Whether a payment's error is the bank asking for its buyer (3-D Secure), not a refusal. */
    public static function needsTheBuyer(Data $error): bool
    {
        return 'authentication_required' === $error->textOrNull('code') || 'authentication_required' === $error->textOrNull('decline_code');
    }

    /**
     * A PaymentMethod kept for a customer: a card by its brand and last four digits, anything
     * else by its type.
     *
     * @param array<string, mixed> $method
     */
    public static function savedMethod(array $method): SavedMethod
    {
        $read = Data::of($method);
        $type = $read->textOrNull('type') ?? 'card';
        $card = $read->data('card');
        $brand = $card->textOrNull('brand');
        $last4 = $card->textOrNull('last4');
        $label = match (true) {
            'card' === $type && null !== $last4 => sprintf('%s •••• %s', self::brand($brand), $last4),
            'sepa_debit' === $type && null !== ($iban = $read->textOrNull('sepa_debit.last4')) => sprintf('SEPA •••• %s', $iban),
            'link' === $type => 'Link'.(null !== ($email = $read->textOrNull('link.email')) ? ' '.$email : ''),
            default => ucfirst(str_replace('_', ' ', $type)),
        };

        return new SavedMethod(
            reference: $read->text('id'),
            customer: \is_array($method['customer'] ?? null) ? $read->textOrNull('customer.id') : $read->textOrNull('customer'),
            code: $type,
            label: $label,
            kind: match ($type) {
                'card' => PaymentMethod::CARD,
                'sepa_debit', 'us_bank_account', 'bacs_debit', 'au_becs_debit' => PaymentMethod::BANK,
                'link', 'paypal', 'cashapp' => PaymentMethod::WALLET,
                default => PaymentMethod::OTHER,
            },
            brand: $brand,
            last4: $last4 ?? $read->textOrNull('sepa_debit.last4'),
            expiryMonth: $card->has('exp_month') ? $card->number('exp_month') : null,
            expiryYear: $card->has('exp_year') ? $card->number('exp_year') : null,
            raw: $method,
        );
    }

    private static function brand(?string $brand): string
    {
        return match ($brand) {
            'amex' => 'American Express',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay',
            'mastercard' => 'Mastercard',
            'visa' => 'Visa',
            'cartes_bancaires' => 'Cartes Bancaires',
            null, '', 'unknown' => 'Card',
            default => ucfirst($brand),
        };
    }
}
