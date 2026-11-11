<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay\Message;

use Omnipay\Common\Message\AbstractResponse;

/**
 * {"refund":{"id":"…","status":"pending|processing|complete|failed",…}}
 */
class RefundResponse extends AbstractResponse
{
    public function isSuccessful(): bool
    {
        $refund = $this->data['refund'] ?? null;

        return in_array((int) ($this->data['code'] ?? 0), [200, 201, 202], true)
            && is_array($refund)
            && ($refund['status'] ?? null) !== 'failed';
    }

    public function getTransactionReference(): ?string
    {
        $refund = $this->data['refund'] ?? null;

        return is_array($refund) && is_string($refund['id'] ?? null) ? $refund['id'] : null;
    }

    public function getMessage(): ?string
    {
        return is_string($this->data['message'] ?? null) ? $this->data['message'] : null;
    }
}
