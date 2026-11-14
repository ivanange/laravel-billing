<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay\Message;

use Omnipay\Common\Message\AbstractRequest as BaseRequest;

/**
 * Auth: "Authorization: <public key>" (no Bearer prefix) on every call;
 * "X-Grant: <private key>" additionally on sensitive endpoints (refunds).
 * One base URL serves sandbox and live: the key decides.
 *
 * @see https://developer.notchpay.co/get-started/authentication.md
 */
abstract class AbstractRequest extends BaseRequest
{
    public const BASE_URL = 'https://api.notchpay.co';

    public function getPublicKey(): ?string
    {
        return $this->getParameter('publicKey');
    }

    public function setPublicKey(?string $value): static
    {
        $this->setParameter('publicKey', $value);

        return $this;
    }

    public function getPrivateKey(): ?string
    {
        return $this->getParameter('privateKey');
    }

    public function setPrivateKey(?string $value): static
    {
        $this->setParameter('privateKey', $value);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function call(string $method, string $path, ?array $body = null, bool $grant = false): array
    {
        $this->validate('publicKey');
        if ($grant) {
            $this->validate('privateKey');
        }

        $headers = array_filter([
            'Authorization' => (string) $this->getPublicKey(),
            'X-Grant' => $grant ? (string) $this->getPrivateKey() : null,
            'Accept' => 'application/json',
            'Content-Type' => $body === null ? null : 'application/json',
        ]);

        $response = $this->httpClient->request($method, self::BASE_URL.$path, $headers, $body === null ? null : (string) json_encode($body));
        $decoded = json_decode((string) $response->getBody(), true);

        return (is_array($decoded) ? $decoded : []) + ['code' => $response->getStatusCode()];
    }
}
