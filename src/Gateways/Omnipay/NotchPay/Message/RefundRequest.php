<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay\Message;

/**
 * POST /refunds (needs X-Grant). Omit "amount" for a full refund.
 *
 * @see https://developer.notchpay.co/accept-payments/refunds.md
 */
class RefundRequest extends AbstractRequest
{
    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->validate('transactionReference');

        return array_filter([
            'payment' => $this->getTransactionReference(),
            'amount' => $this->getAmount() === null ? null : $this->getAmountInteger(),
            'reason' => $this->getDescription() ?? 'Refund',
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendData($data): RefundResponse
    {
        return $this->response = new RefundResponse($this, $this->call('POST', '/refunds', $data, grant: true));
    }
}
