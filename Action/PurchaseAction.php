<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Payment;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use Omnitrade\Stripe\Api;
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
 */
final class PurchaseAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

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
            'metadata' => $payment->metadata ? array_map('strval', $payment->metadata) : null,
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
        $data = $this->api->sendOmnipay($session)->getData();

        if (empty($data['id']) || empty($data['url'])) {
            throw new ProviderException('stripe', (string) ($data['error']['message'] ?? 'Stripe opened no Checkout session.'), $data['error']['code'] ?? null);
        }

        $request->setResult(Sessions::transaction($data));
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
        return $payment->linesAddUp() && ($payment->discount?->amount ?? 0) <= 0;
    }

    private static function withQuery(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
