<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay\Message;

/**
 * POST /payments: create a payment and get the hosted checkout URL.
 * "amount" is in the smallest currency unit (whole francs for XAF).
 *
 * @see https://developer.notchpay.co/api-reference/payments.md
 */
class PurchaseRequest extends AbstractRequest
{
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

    /** NotchPay channel the checkout is locked to, e.g. cm.mtn or cm.orange. */
    public function setChannel(?string $value): static
    {
        $this->setParameter('channel', $value);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->validate('amount', 'currency', 'transactionReference', 'returnUrl');

        return array_filter([
            'amount' => $this->getAmountInteger(),
            'currency' => $this->getCurrency(),
            'reference' => $this->getTransactionReference(),
            'callback' => $this->getReturnUrl(),
            'description' => $this->getDescription(),
            'email' => $this->getParameter('email'),
            'phone' => $this->getParameter('phone'),
            'locked_channel' => $this->getParameter('channel'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendData($data): PurchaseResponse
    {
        return $this->response = new PurchaseResponse($this, $this->call('POST', '/payments', $data));
    }
}
