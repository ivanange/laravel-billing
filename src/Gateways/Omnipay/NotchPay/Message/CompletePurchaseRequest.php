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

        $data = $this->call('GET', "/payments/{$reference}");

        // A refused or unreadable answer (bad key, unknown reference, HTML
        // error page) must surface as an error, never as "still pending".
        if (! in_array((int) $data['code'], [200, 201, 202], true) || ! is_array($data['transaction'] ?? null)) {
            throw new \RuntimeException(sprintf('NotchPay verify failed (HTTP %d): %s', $data['code'], is_string($data['message'] ?? null) ? $data['message'] : 'no transaction in response'));
        }

        return $this->response = new TransactionResponse($this, $data);
    }
}
