<?php

namespace Omnitrade\Stripe;

use Omnipay\Stripe\Message\RefundRequest as OmnipayRefundRequest;

/**
 * omnipay/stripe's refund of a charge (POST /v1/charges/{charge}/refund), with
 * the metadata it does not send: the gateway's tags (site), kept on the
 * refund. The endpoint takes "metadata" (Stripe's OpenAPI description,
 * PostChargesChargeRefund); without any, the request is the parent's.
 */
class RefundRequest extends OmnipayRefundRequest
{
    /** @return array<string, mixed> */
    public function getData(): array
    {
        $data = Data::map(parent::getData());
        $metadata = $this->getMetadata();
        if (\is_array($metadata) && [] !== $metadata) {
            $data['metadata'] = $metadata;
        }

        return $data;
    }
}
