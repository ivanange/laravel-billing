<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay\Message;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RedirectResponseInterface;

/**
 * 201 {"status":"Accepted","transaction":{...},"authorization_url":"https://pay.notchpay.co/..."}
 * The docs disagree on whether "transaction" is an object or an id string,
 * so only authorization_url is relied on.
 *
 * @see https://developer.notchpay.co/api-reference/payments.md
 */
class PurchaseResponse extends AbstractResponse implements RedirectResponseInterface
{
    public function isSuccessful(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return AbstractRequest::succeeded($this->data) && $this->getRedirectUrl() !== null;
    }

    public function getRedirectUrl(): ?string
    {
        $url = $this->data['authorization_url'] ?? null;

        return is_string($url) && self::trustedHost($url) ? $url : null;
    }

    /** Only send payers to the provider's own https hosts. */
    private static function trustedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($url, 'https://') && ($host === 'notchpay.co' || str_ends_with($host, '.notchpay.co'));
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    /**
     * @return array<string, mixed>
     */
    public function getRedirectData(): array
    {
        return [];
    }

    /** NotchPay's own reference (trx.…) when the response carries one. */
    public function getTransactionReference(): ?string
    {
        $transaction = $this->data['transaction'] ?? null;

        // The docs show "transaction" both as an object and as a bare id.
        return match (true) {
            is_string($transaction) && $transaction !== '' => $transaction,
            is_array($transaction) && is_string($transaction['reference'] ?? null) => $transaction['reference'],
            is_array($transaction) && is_string($transaction['id'] ?? null) => $transaction['id'],
            default => null,
        };
    }

    public function getMessage(): ?string
    {
        return is_string($this->data['message'] ?? null) ? $this->data['message'] : null;
    }
}
