<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Hubtel\Message;

use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Common\Message\RedirectResponseInterface;

/**
 * {"responseCode":"0000","status":"Success","data":{"checkoutUrl":"…","checkoutId":"…","clientReference":"…"}}
 */
class PurchaseResponse extends AbstractResponse implements RedirectResponseInterface
{
    public function isSuccessful(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return ($this->data['responseCode'] ?? null) === '0000' && $this->getRedirectUrl() !== null;
    }

    public function getRedirectUrl(): ?string
    {
        $url = $this->data['data']['checkoutUrl'] ?? null;

        return is_string($url) && self::trustedHost($url) ? $url : null;
    }

    /** Only send payers to the provider's own https hosts. */
    private static function trustedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($url, 'https://') && ($host === 'hubtel.com' || str_ends_with($host, '.hubtel.com'));
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

    public function getTransactionReference(): ?string
    {
        $id = $this->data['data']['checkoutId'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function getMessage(): ?string
    {
        $message = $this->data['data']['message'] ?? $this->data['message'] ?? $this->data['status'] ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    public function getCode(): ?string
    {
        return is_string($this->data['responseCode'] ?? null) ? $this->data['responseCode'] : null;
    }
}
