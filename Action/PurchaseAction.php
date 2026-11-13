<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
use Omnitrade\Stripe\Data;
use Omnitrade\Stripe\Intents;
use Omnitrade\Stripe\Sessions;

/**
 * A Checkout session: Stripe's hosted page, the buyer sent to it (PENDING with
 * the page's URL); notify() or fetch() then says whether they paid. The lines
 * are listed on the page when they and the shipping add up to the amount, the
 * shipping as the session's shipping rate; else (a discount among them, which
 * Checkout takes only as a coupon made beforehand, or lines that do not add
 * up) the amount is one line under the payment's description. Either way the
 * session charges the payment's amount.
 *
 * A payment with a destination is a Connect destination charge: taken by the
 * platform, transferred to the connected account, the application fee kept.
 *
 * The gateway's tags (the "tags" option: site) and the payment's metadata are
 * the session's metadata and its payment intent's (payment_intent_data.metadata).
 */
final class PurchaseAction implements ActionInterface, ApiAwareInterface, TagsAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;
    use TagsAwareTrait;

    /** @param string[] $paymentMethods */
    public function __construct(private readonly bool $adaptivePricing = false, private readonly array $paymentMethods = [])
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        $payment = $request->payment;
        if (null !== $payment->savedMethod && '' !== $payment->savedMethod) {
            $request->setResult($this->offSession($payment));

            return;
        }
        if (null === $payment->returnUrl) {
            throw new ProviderException('stripe', 'A Checkout session needs a returnUrl: where Stripe sends the buyer back.');
        }

        $session = $this->api->checkout()->purchase([
            'mode' => 'payment',
            'line_items' => self::lines($payment),
            'shippingOptions' => self::shipping($payment),
            'customerEmail' => $payment->customer?->email,
            'clientReferenceId' => $payment->reference,
            'notice' => $payment->notice,
            'locale' => $payment->locale ? substr($payment->locale, 0, 2) : null,
            'metadata' => $this->tags()->merge($payment->metadata) ?: null,
            'adaptivePricing' => $this->adaptivePricing,
            'destination' => $payment->destination,
            'applicationFee' => $payment->applicationFee?->amount,
            'paymentMethodTypes' => $payment->method ? [$payment->method] : ($this->paymentMethods ?: null),
            // Stripe fills {CHECKOUT_SESSION_ID} in; the braces must survive URL encoding.
            'success_url' => str_replace('SESSION_ID_PLACEHOLDER', '{CHECKOUT_SESSION_ID}', self::withQuery($payment->returnUrl, ['session' => 'SESSION_ID_PLACEHOLDER'])),
            'cancel_url' => $payment->cancelUrl ?? self::withQuery($payment->returnUrl, ['cancel' => '1']),
        ]);
        if ($payment->idempotencyKey) {
            $session->setIdempotencyKeyHeader($payment->idempotencyKey);
        }
        $data = Data::map($this->api->sendOmnipay($session)->getData());

        if (empty($data['id']) || empty($data['url'])) {
            $error = Data::of($data)->data('error');

            throw new ProviderException('stripe', $error->textOrNull('message') ?? 'Stripe opened no Checkout session.', $error->textOrNull('code'));
        }

        $request->setResult(Sessions::transaction($data));
    }

    /**
     * A saved method charged with no page (Payment::$savedMethod): a PaymentIntent confirmed at
     * once, off-session, for the payment's customer. Paid at once is PAID; a card refused is
     * REFUSED, with Stripe's message and decline code. When the bank asks for its buyer
     * (authentication_required: 3-D Secure), the intent is confirmed again with the payment's
     * returnUrl, on-session: PENDING, the bank's page as the redirectUrl - the buyer goes there,
     * comes back to returnUrl, and payment_intent.succeeded (notify()) or fetch() says how it
     * ended. Without a returnUrl there is nowhere to come back to: REFUSED, code
     * authentication_required.
     *
     * The idempotency key is the intent's, and with ":confirm" its confirmation's: the same
     * purchase asked twice charges once.
     */
    private function offSession(Payment $payment): Transaction
    {
        $metadata = $this->tags()->merge($payment->metadata + ['reference' => $payment->reference]) ?: null;
        $intent = $this->api->attempt('/v1/payment_intents', [
            'amount' => $payment->amount->amount,
            'currency' => strtolower($payment->amount->currency),
            'customer' => $payment->customer?->reference,
            'payment_method' => $payment->savedMethod,
            'off_session' => true,
            'confirm' => true,
            'description' => $payment->description ?? $payment->reference,
            'receipt_email' => $payment->customer?->email,
            'metadata' => $metadata,
            'transfer_data' => null !== $payment->destination ? ['destination' => $payment->destination] : null,
            'application_fee_amount' => $payment->applicationFee?->amount,
        ], $payment->idempotencyKey);

        $error = Data::of($intent)->data('error');
        if ($error->has('code') || $error->has('message')) {
            $refused = $error->fields('payment_intent');
            if (!isset($refused['id'])) {
                throw new ProviderException('stripe', $error->textOrNull('message') ?? 'Stripe refused the payment.', $error->textOrNull('decline_code') ?? $error->textOrNull('code'));
            }
            if (!Intents::needsTheBuyer($error)) {
                return self::refused($refused, $error);
            }
            $intent = $refused;
        }

        $read = Data::of($intent);
        if (\in_array($read->textOrNull('status'), ['requires_action', 'requires_payment_method', 'requires_confirmation'], true)
            && 'redirect_to_url' !== $read->textOrNull('next_action.type')) {
            if (null === $payment->returnUrl) {
                return self::refused($intent, Data::of(['code' => 'authentication_required', 'message' => 'The bank asks the buyer to confirm, and there is no page to send them back to.']));
            }
            // The buyer is asked: confirmed again on-session, Stripe answering with the bank's page.
            $intent = $this->api->attempt('/v1/payment_intents/'.$read->text('id').'/confirm', [
                'payment_method' => $payment->savedMethod,
                'return_url' => $payment->returnUrl,
            ], null !== $payment->idempotencyKey ? $payment->idempotencyKey.':confirm' : null);
            $error = Data::of($intent)->data('error');
            if ($error->has('code') || $error->has('message')) {
                return self::refused($error->has('payment_intent') ? $error->fields('payment_intent') : Data::map($read->toArray()), $error);
            }
        }

        return Intents::transaction($intent);
    }

    /**
     * The intent Stripe refused, REFUSED whatever status it was left in, with the error's message and code.
     *
     * @param array<string, mixed> $intent
     */
    private static function refused(array $intent, Data $error): Transaction
    {
        $transaction = Intents::transaction($intent);

        return new Transaction(
            provider: 'stripe',
            reference: $transaction->reference,
            status: Status::REFUSED,
            amount: $transaction->amount,
            message: $error->textOrNull('message') ?? $transaction->message,
            providerCode: $error->textOrNull('decline_code') ?? $error->textOrNull('code') ?? $transaction->providerCode,
            method: $transaction->method,
            metadata: $transaction->metadata,
            createdAt: $transaction->createdAt,
            raw: $intent,
        );
    }

    /** @return list<array<string, mixed>> the session's line items, which with shipping() charge the payment's amount */
    public static function lines(Payment $payment): array
    {
        $currency = strtolower($payment->amount->currency);
        if (self::listed($payment)) {
            $lines = [];
            foreach ($payment->lines as $line) {
                $lines[] = [
                    'quantity' => $line->quantity,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $line->unitAmount->amount,
                        'product_data' => array_filter(['name' => $line->label, 'images' => $line->imageUrl ? [$line->imageUrl] : null]),
                    ],
                ];
            }

            return $lines;
        }

        return [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $payment->amount->amount,
                'product_data' => ['name' => $payment->description ?? $payment->reference],
            ],
        ]];
    }

    /**
     * The shipping as the session's one shipping rate, a fixed amount, when the
     * lines are listed and there is shipping to pay; else null (it is inside the
     * one line).
     *
     * @return list<array<string, mixed>>|null
     */
    public static function shipping(Payment $payment): ?array
    {
        if (!self::listed($payment) || null === $payment->shipping || $payment->shipping->amount <= 0) {
            return null;
        }

        return [[
            'shipping_rate_data' => [
                'type' => 'fixed_amount',
                'display_name' => 'Shipping',
                'fixed_amount' => ['amount' => $payment->shipping->amount, 'currency' => strtolower($payment->shipping->currency)],
            ],
        ]];
    }

    /** Whether the lines are listed: they and the shipping add up to the amount, with no discount to take off. */
    private static function listed(Payment $payment): bool
    {
        return $payment->linesAddUp() && ($payment->discount->amount ?? 0) <= 0;
    }

    /** @param array<string, string> $query */
    private static function withQuery(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
