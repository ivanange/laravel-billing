<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay\Message;

use Omnipay\Common\Message\AbstractResponse;

/**
 * {"code":200,"transaction":{"reference":"…","amount":5000,"currency":"XAF","status":"complete",…}}
 *
 * Statuses: pending, processing, complete, failed, canceled, expired
 * (API reference) plus incomplete, rejected, abandoned, refunded,
 * partialy-refunded (collect guide).
 *
 * @see https://developer.notchpay.co/api-reference/payments.md
 * @see https://developer.notchpay.co/accept-payments/collect.md
 */
class TransactionResponse extends AbstractResponse
{
    public const FAILED = ['failed', 'canceled', 'expired', 'rejected', 'abandoned', 'incomplete', 'refunded', 'partialy-refunded'];

    /**
     * @return array<string, mixed>
     */
    public function transaction(): array
    {
        $transaction = $this->data['transaction'] ?? null;

        return is_array($transaction) ? $transaction : [];
    }

    public function status(): string
    {
        return strtolower((string) ($this->transaction()['status'] ?? ''));
    }

    public function isSuccessful(): bool
    {
        return $this->status() === 'complete';
    }

    public function isFailed(): bool
    {
        return in_array($this->status(), self::FAILED, true);
    }

    public function getTransactionReference(): ?string
    {
        $reference = $this->transaction()['reference'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    public function getMessage(): ?string
    {
        return is_string($this->data['message'] ?? null) ? $this->data['message'] : null;
    }
}
