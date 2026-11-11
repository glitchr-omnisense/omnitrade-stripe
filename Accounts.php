<?php

namespace Omnitrade\Stripe;

use Omnitrade\Model\Account;
use Omnitrade\Model\Money;
use Omnitrade\Model\Subscription;

/** Stripe's account and subscription objects as Omnitrade's models. */
final class Accounts
{
    /** @param array<string, mixed> $account */
    public static function account(array $account): Account
    {
        $read = Data::of($account);
        $requirements = array_merge((array) $read->get('requirements.currently_due'), (array) $read->get('requirements.past_due'));

        return new Account(
            provider: 'stripe',
            reference: $read->text('id'),
            type: $read->textOrNull('type') ?? Account::EXPRESS,
            country: $read->textOrNull('country'),
            email: $read->textOrNull('email'),
            detailsSubmitted: (bool) ($account['details_submitted'] ?? false),
            chargesEnabled: (bool) ($account['charges_enabled'] ?? false),
            payoutsEnabled: (bool) ($account['payouts_enabled'] ?? false),
            requirements: array_values(array_unique(Data::texts($requirements))),
            defaultCurrency: null !== ($currency = $read->textOrNull('default_currency')) ? strtoupper($currency) : null,
            metadata: $read->fields('metadata'),
            raw: $account,
        );
    }

    /** @param array<string, mixed> $subscription */
    public static function subscription(array $subscription): Subscription
    {
        $read = Data::of($subscription);
        $item = Data::of($read->get('items.data.0') ?? []);
        $price = $item->data('price');
        $currency = strtoupper(Data::cast($price->get('currency') ?? $read->get('currency') ?? 'EUR'));
        $time = static fn (mixed $t) => \is_int($t) ? (new \DateTimeImmutable())->setTimestamp($t) : null;
        $status = match ($read->text('status')) {
            'active' => Subscription::ACTIVE,
            'trialing' => Subscription::TRIALING,
            'past_due' => Subscription::PAST_DUE,
            'unpaid' => Subscription::UNPAID,
            'canceled', 'incomplete_expired' => Subscription::CANCELLED,
            'paused' => Subscription::PAUSED,
            default => Subscription::INCOMPLETE,
        };

        return new Subscription(
            provider: 'stripe',
            reference: $read->text('id'),
            status: $status,
            customer: \is_array($read->get('customer')) ? $read->text('customer.id') : $read->textOrNull('customer'),
            amount: $price->has('unit_amount') ? Money::of($price->number('unit_amount') * Data::integer($item->get('quantity') ?? 1), $currency) : null,
            interval: $price->textOrNull('recurring.interval'),
            intervalCount: Data::integer($price->get('recurring.interval_count') ?? 1),
            // The period moved from the subscription to its items in Stripe's newer API versions: read both.
            currentPeriodStart: $time($read->get('current_period_start') ?? $item->get('current_period_start')),
            currentPeriodEnd: $time($read->get('current_period_end') ?? $item->get('current_period_end')),
            cancelAtPeriodEnd: (bool) ($subscription['cancel_at_period_end'] ?? false),
            cancelledAt: $time($read->get('canceled_at')),
            price: $price->textOrNull('id'),
            metadata: $read->fields('metadata'),
            raw: $subscription,
        );
    }
}
