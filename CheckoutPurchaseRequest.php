<?php

namespace Omnitrade\Stripe;

use Omnipay\Stripe\Message\Checkout\PurchaseRequest;

/**
 * omnipay/stripe's Checkout session request, with what it does not send:
 *
 *   customer_email       the buyer's address, filled in on Stripe's page
 *   adaptive_pricing     off by default: the session is paid in the given
 *                        currency, not converted to the buyer's
 *   locale               the page's language
 *   client_reference_id  the merchant's reference (the order's) - the parent's parameter, sent here
 *   metadata             kept by Stripe on the session and on its payment intent
 *                        (payment_intent_data.metadata), given back on every event
 *   notice               a mention on the page and the receipt (why no VAT...)
 *   destination          a connected account the payment is passed on to (Connect destination charge)
 *   applicationFee       what the platform keeps of it, in minor units
 *   shippingOptions      shipping_options: the shipping rate charged on top of the lines
 *
 * The methods to insist on (paymentMethodTypes) and the Idempotency-Key header
 * (setIdempotencyKeyHeader) are the parent's.
 */
class CheckoutPurchaseRequest extends PurchaseRequest
{
    public function getCustomerEmail(): ?string { return self::text($this->getParameter('customerEmail')); }
    public function setCustomerEmail(?string $value): self { return $this->setParameter('customerEmail', $value); }
    public function getAdaptivePricing(): bool { return (bool) $this->getParameter('adaptivePricing'); }
    public function setAdaptivePricing(bool $value): self { return $this->setParameter('adaptivePricing', $value); }
    public function getLocale(): ?string { return self::text($this->getParameter('locale')); }
    public function setLocale(?string $value): self { return $this->setParameter('locale', $value); }
    public function getDestination(): ?string { return self::text($this->getParameter('destination')); }
    public function setDestination(?string $value): self { return $this->setParameter('destination', $value); }
    public function getApplicationFee(): ?int { $value = $this->getParameter('applicationFee'); return null === $value ? null : Data::integer($value); }
    public function setApplicationFee(?int $value): self { return $this->setParameter('applicationFee', $value); }
    /** @return list<array<string, mixed>>|null */
    public function getShippingOptions(): ?array { $value = $this->getParameter('shippingOptions'); return \is_array($value) ? Data::maps($value) : null; }
    /** @param list<array<string, mixed>>|null $value */
    public function setShippingOptions(?array $value): self { return $this->setParameter('shippingOptions', $value); }
    public function getNotice(): ?string { return self::text($this->getParameter('notice')); }
    public function setNotice(?string $value): self { return $this->setParameter('notice', $value); }

    /** @return array<mixed> */
    public function getData(): array
    {
        $data = array_filter((array) parent::getData(), static fn ($value) => null !== $value);
        $intent = [];
        if ($this->getCustomerEmail()) {
            $data['customer_email'] = $this->getCustomerEmail();
        }
        // Form-encoded: Stripe reads the strings "true" / "false".
        $data['adaptive_pricing'] = ['enabled' => $this->getAdaptivePricing() ? 'true' : 'false'];
        if ($this->getLocale()) {
            $data['locale'] = $this->getLocale();
        }
        if ($this->getClientReferenceId()) {
            $data['client_reference_id'] = $this->getClientReferenceId();
        }
        if ($this->getShippingOptions()) {
            $data['shipping_options'] = $this->getShippingOptions();
        }
        if ($this->getMetadata()) {
            $data['metadata'] = $this->getMetadata();
            // The payment intent (and what is read of it later: its charge's refunds, its events) carries them too.
            $intent = ['metadata' => $this->getMetadata()];
            $data['payment_intent_data'] = $intent;
        }
        if ($this->getNotice()) {
            $data['custom_text'] = ['submit' => ['message' => $this->getNotice()]];
            $intent += ['description' => $this->getNotice()];
            $data['payment_intent_data'] = $intent;
        }
        // Connect, a destination charge: the platform takes the payment, Stripe
        // passes it on to the connected account, less the platform's fee.
        if ($this->getDestination()) {
            $intent += ['transfer_data' => ['destination' => $this->getDestination()]];
            if (null !== $this->getApplicationFee() && $this->getApplicationFee() > 0) {
                $intent['application_fee_amount'] = $this->getApplicationFee();
            }
            $data['payment_intent_data'] = $intent;
        }

        return $data;
    }

    /** A string parameter as its setter took it (a scalar as a string, as the return type made it). */
    private static function text(mixed $value): ?string
    {
        return null === $value ? null : Data::cast($value);
    }
}
