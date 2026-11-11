<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay\Message;

/**
 * GET /payments/{reference}: the payment's current status.
 *
 * @see https://developer.notchpay.co/api-reference/payments.md
 */
class CompletePurchaseRequest extends AbstractRequest
{
    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->validate('transactionReference');

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendData($data): TransactionResponse
    {
        $reference = rawurlencode((string) $this->getTransactionReference());

        return $this->response = new TransactionResponse($this, $this->call('GET', "/payments/{$reference}"));
    }
}
