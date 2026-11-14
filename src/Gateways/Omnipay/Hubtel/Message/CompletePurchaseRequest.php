<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Hubtel\Message;

/**
 * GET https://api-txnstatus.hubtel.com/transactions/{merchantAccount}/status?clientReference=…
 * Only answers whitelisted server IPs.
 *
 * @see https://developers.hubtel.com/docs/business/api_documentation/payment_apis/online_checkout
 */
class CompletePurchaseRequest extends AbstractRequest
{
    public const URL = 'https://api-txnstatus.hubtel.com/transactions/%s/status?clientReference=%s';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->validate('transactionReference', 'merchantAccount');

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendData($data): StatusResponse
    {
        $url = sprintf(self::URL, rawurlencode($this->getMerchantAccount()), rawurlencode((string) $this->getTransactionReference()));

        $data = $this->call('GET', $url);

        // 403 = this server's IP is not whitelisted by Hubtel; anything but a
        // "0000" with data is an error, never "still pending".
        if ((int) $data['httpStatus'] !== 200 || ($data['responseCode'] ?? null) !== '0000' || ! is_array($data['data'] ?? null)) {
            throw new \RuntimeException(sprintf('Hubtel status check failed (HTTP %d, code %s)%s', $data['httpStatus'], is_scalar($data['responseCode'] ?? null) ? $data['responseCode'] : '-', (int) $data['httpStatus'] === 403 ? ': is this server IP whitelisted by Hubtel?' : ''));
        }

        return $this->response = new StatusResponse($this, $data);
    }
}
