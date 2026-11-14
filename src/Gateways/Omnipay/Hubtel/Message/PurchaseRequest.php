<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Hubtel\Message;

use Omnipay\Common\Exception\InvalidRequestException;

/**
 * POST https://payproxyapi.hubtel.com/items/initiate
 *
 * totalAmount is in cedis with at most 2 decimals; clientReference is at
 * most 32 characters; description must not contain special characters.
 *
 * @see https://developers.hubtel.com/docs/business/api_documentation/payment_apis/online_checkout
 */
class PurchaseRequest extends AbstractRequest
{
    public const URL = 'https://payproxyapi.hubtel.com/items/initiate';

    public function setEmail(?string $value): static
    {
        $this->setParameter('email', $value);

        return $this;
    }

    public function setPhone(?string $value): static
    {
        $this->setParameter('phone', $value);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->validate('amount', 'transactionReference', 'returnUrl', 'notifyUrl', 'merchantAccount');

        return array_filter([
            'totalAmount' => round((float) $this->getAmount(), 2),
            'description' => $this->cleanDescription(),
            'callbackUrl' => $this->getNotifyUrl(),
            'returnUrl' => $this->getReturnUrl(),
            'cancellationUrl' => $this->getParameter('cancelUrl') ?: $this->getReturnUrl(),
            'merchantAccountNumber' => $this->getMerchantAccount(),
            'clientReference' => $this->clientReference(),
            'payeeEmail' => $this->getParameter('email'),
            'payeeMobileNumber' => $this->getParameter('phone'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendData($data): PurchaseResponse
    {
        return $this->response = new PurchaseResponse($this, $this->call('POST', self::URL, $data));
    }

    /** Hubtel allows 32 characters; truncating would break the later status lookup. */
    private function clientReference(): string
    {
        $reference = (string) $this->getTransactionReference();

        if (strlen($reference) > 32) {
            throw new InvalidRequestException('Hubtel client references are limited to 32 characters.');
        }

        return $reference;
    }

    private function cleanDescription(): string
    {
        $clean = trim((string) preg_replace('/[^\pL\pN ]+/u', ' ', (string) ($this->getParameter('description') ?: 'Payment')));

        return mb_substr((string) preg_replace('/\s+/', ' ', $clean), 0, 100) ?: 'Payment';
    }
}
