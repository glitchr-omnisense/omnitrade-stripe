<?php

namespace Omnitrade\Stripe\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Request\GetPaymentMethods;
use Omnitrade\Request\Request;

/**
 * What Checkout offers: the methods insisted on in the options, else the
 * card plus the wallets Stripe shows on its own (Apple Pay, Google Pay, Link)
 * - Stripe decides the rest per account, country and currency on its page.
 * The amount's currency narrows them: the euro-only methods (SEPA Direct
 * Debit, Bancontact, iDEAL, EPS) are left out of an amount in another
 * currency (Stripe's payment method support table).
 */
final class GetPaymentMethodsAction implements ActionInterface
{
    private const KNOWN = [
        'card' => ['Card', PaymentMethod::CARD],
        'apple_pay' => ['Apple Pay', PaymentMethod::WALLET],
        'google_pay' => ['Google Pay', PaymentMethod::WALLET],
        'link' => ['Link', PaymentMethod::WALLET],
        'paypal' => ['PayPal', PaymentMethod::WALLET],
        'sepa_debit' => ['SEPA Direct Debit', PaymentMethod::BANK],
        'bancontact' => ['Bancontact', PaymentMethod::BANK],
        'ideal' => ['iDEAL', PaymentMethod::BANK],
        'eps' => ['EPS', PaymentMethod::BANK],
        'klarna' => ['Klarna', PaymentMethod::BUY_NOW_PAY_LATER],
        'afterpay_clearpay' => ['Afterpay / Clearpay', PaymentMethod::BUY_NOW_PAY_LATER],
    ];

    /** Methods that take one currency only. */
    private const CURRENCIES = ['sepa_debit' => ['EUR'], 'bancontact' => ['EUR'], 'ideal' => ['EUR'], 'eps' => ['EUR']];

    /** @param string[] $paymentMethods */
    public function __construct(private readonly array $paymentMethods = [])
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof GetPaymentMethods;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetPaymentMethods);
        $codes = $this->paymentMethods ?: ['card', 'apple_pay', 'google_pay', 'link'];
        $methods = [];
        foreach ($codes as $code) {
            if (null !== $request->for && isset(self::CURRENCIES[$code]) && !\in_array($request->for->currency, self::CURRENCIES[$code], true)) {
                continue;
            }
            [$label, $kind] = self::KNOWN[$code] ?? [ucfirst(str_replace('_', ' ', $code)), PaymentMethod::OTHER];
            $methods[] = new PaymentMethod($code, $label, $kind);
        }
        $request->setResult($methods);
    }
}
