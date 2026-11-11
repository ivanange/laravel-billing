<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\NotchPay;

use Ivanange\Billing\Gateways\Omnipay\NotchPay\Message\CompletePurchaseRequest;
use Ivanange\Billing\Gateways\Omnipay\NotchPay\Message\PurchaseRequest;
use Ivanange\Billing\Gateways\Omnipay\NotchPay\Message\RefundRequest;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\RequestInterface;

/**
 * Thin Omnipay driver for NotchPay (Cameroon: MTN Mobile Money, Orange
 * Money; XAF). No Omnipay driver exists for NotchPay, so this one only
 * covers what the package needs: hosted checkout, verify and refund.
 *
 * @see https://developer.notchpay.co/get-started/authentication.md
 * @see https://developer.notchpay.co/api-reference/payments.md
 */
class Gateway extends AbstractGateway
{
    public function getName(): string
    {
        return 'NotchPay';
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultParameters(): array
    {
        return ['publicKey' => '', 'privateKey' => ''];
    }

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
     * @param  array<string, mixed>  $parameters
     */
    public function purchase(array $parameters = []): RequestInterface
    {
        return $this->createRequest(PurchaseRequest::class, $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function completePurchase(array $parameters = []): RequestInterface
    {
        return $this->createRequest(CompletePurchaseRequest::class, $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function refund(array $parameters = []): RequestInterface
    {
        return $this->createRequest(RefundRequest::class, $parameters);
    }
}
