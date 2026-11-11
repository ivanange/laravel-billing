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

        return $this->response = new StatusResponse($this, $this->call('GET', $url));
    }
}
