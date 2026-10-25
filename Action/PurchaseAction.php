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
 * are listed on the page when they add up to the amount, else the amount is
 * one line under the payment's description.
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

    /** @return list<array<string, mixed>> */
    public static function lines(Payment $payment): array
    {
        $currency = strtolower($payment->amount->currency);
        if ($payment->linesAddUp()) {
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

    private static function withQuery(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
