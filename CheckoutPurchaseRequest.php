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
 *   metadata             kept by Stripe, given back on every event
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
    public function getCustomerEmail(): ?string { return $this->getParameter('customerEmail'); }
    public function setCustomerEmail(?string $value): self { return $this->setParameter('customerEmail', $value); }
    public function getAdaptivePricing(): bool { return (bool) $this->getParameter('adaptivePricing'); }
    public function setAdaptivePricing(bool $value): self { return $this->setParameter('adaptivePricing', $value); }
    public function getLocale(): ?string { return $this->getParameter('locale'); }
    public function setLocale(?string $value): self { return $this->setParameter('locale', $value); }
    public function getDestination(): ?string { return $this->getParameter('destination'); }
    public function setDestination(?string $value): self { return $this->setParameter('destination', $value); }
    public function getApplicationFee(): ?int { return $this->getParameter('applicationFee'); }
    public function setApplicationFee(?int $value): self { return $this->setParameter('applicationFee', $value); }
    /** @return list<array<string, mixed>>|null */
    public function getShippingOptions(): ?array { return $this->getParameter('shippingOptions'); }
    /** @param list<array<string, mixed>>|null $value */
    public function setShippingOptions(?array $value): self { return $this->setParameter('shippingOptions', $value); }
    public function getNotice(): ?string { return $this->getParameter('notice'); }
    public function setNotice(?string $value): self { return $this->setParameter('notice', $value); }

    public function getData(): array
    {
        $data = array_filter(parent::getData(), static fn ($value) => null !== $value);
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
        }
        if ($this->getNotice()) {
            $data['custom_text'] = ['submit' => ['message' => $this->getNotice()]];
            $data['payment_intent_data'] = ['description' => $this->getNotice()];
        }
        // Connect, a destination charge: the platform takes the payment, Stripe
        // passes it on to the connected account, less the platform's fee.
        if ($this->getDestination()) {
            $data['payment_intent_data'] = ($data['payment_intent_data'] ?? []) + ['transfer_data' => ['destination' => $this->getDestination()]];
            if (null !== $this->getApplicationFee() && $this->getApplicationFee() > 0) {
                $data['payment_intent_data']['application_fee_amount'] = $this->getApplicationFee();
            }
        }

        return $data;
    }
}
