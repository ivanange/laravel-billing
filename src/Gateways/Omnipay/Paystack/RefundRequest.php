<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Paystack;

use Omnipay\Paystack\Message\RefundRequest as BaseRefundRequest;

/**
 * POST /refund, keeping the HTTP status (under "httpStatus") next to the
 * decoded body. The upstream request drops it, and a 5xx must not be read
 * as Paystack refusing the refund: it may have been queued.
 *
 * @see https://paystack.com/docs/api/refund/#create
 */
class RefundRequest extends BaseRefundRequest
{
    /**
     * @param  string  $method
     * @param  string  $endpoint
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    protected function sendRequest($method, $endpoint, ?array $data = null)
    {
        $response = $this->httpClient->request($method, $this->baseApiEndpoint.$endpoint, [
            'Authorization' => 'Bearer '.$this->getSecretKey(),
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache',
        ], (string) json_encode($data));
        $decoded = json_decode((string) $response->getBody(), true);

        return array_merge(is_array($decoded) ? $decoded : [], ['httpStatus' => $response->getStatusCode()]);
    }
}
