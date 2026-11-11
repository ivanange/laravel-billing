<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Hubtel\Message;

use Omnipay\Common\Message\AbstractRequest as BaseRequest;

/**
 * Basic auth with the API ID and API key.
 *
 * @see https://developers.hubtel.com/docs/business/getting_started/authentication
 */
abstract class AbstractRequest extends BaseRequest
{
    public function setApiId(?string $value): static
    {
        $this->setParameter('apiId', $value);

        return $this;
    }

    public function setApiKey(?string $value): static
    {
        $this->setParameter('apiKey', $value);

        return $this;
    }

    public function setMerchantAccount(?string $value): static
    {
        $this->setParameter('merchantAccount', $value);

        return $this;
    }

    public function getMerchantAccount(): string
    {
        return (string) $this->getParameter('merchantAccount');
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function call(string $method, string $url, ?array $body = null): array
    {
        $credentials = base64_encode($this->getParameter('apiId').':'.$this->getParameter('apiKey'));
        $headers = array_filter([
            'Authorization' => 'Basic '.$credentials,
            'Accept' => 'application/json',
            'Content-Type' => $body === null ? null : 'application/json',
        ]);

        $response = $this->httpClient->request($method, $url, $headers, $body === null ? null : (string) json_encode($body));
        $decoded = json_decode((string) $response->getBody(), true);

        return (is_array($decoded) ? $decoded : []) + ['httpStatus' => $response->getStatusCode()];
    }
}
