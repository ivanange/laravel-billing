<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Hubtel\Message;

use Omnipay\Common\Message\AbstractResponse;

/**
 * {"responseCode":"0000","data":{"status":"Paid|Unpaid|Refunded","amount":0.1,"currencyCode":null,
 *  "clientReference":"…","transactionId":"…",…}}
 */
class StatusResponse extends AbstractResponse
{
    /**
     * @return array<string, mixed>
     */
    public function transaction(): array
    {
        $data = $this->data['data'] ?? null;

        return ($this->data['responseCode'] ?? null) === '0000' && is_array($data) ? $data : [];
    }

    public function status(): string
    {
        return strtolower((string) ($this->transaction()['status'] ?? ''));
    }

    public function isSuccessful(): bool
    {
        return $this->status() === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status() === 'refunded';
    }

    public function getTransactionReference(): ?string
    {
        $id = $this->transaction()['transactionId'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function getMessage(): ?string
    {
        return is_string($this->data['message'] ?? null) ? $this->data['message'] : null;
    }
}
