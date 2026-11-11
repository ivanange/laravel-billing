<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Hubtel;

use Ivanange\Billing\Gateways\Omnipay\Hubtel\Message\CompletePurchaseRequest;
use Ivanange\Billing\Gateways\Omnipay\Hubtel\Message\PurchaseRequest;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\RequestInterface;

/**
 * Thin Omnipay driver for Hubtel Online Checkout (Ghana, GHS): the payer
 * picks mobile money (MTN, Telecel, AirtelTigo) or card on Hubtel's page.
 * No Omnipay driver exists for Hubtel.
 *
 * The transaction-status API only answers whitelisted server IPs: ask
 * Hubtel to whitelist the app's outgoing IPs before going live.
 *
 * @see https://developers.hubtel.com/docs/business/api_documentation/payment_apis/online_checkout
 * @see https://developers.hubtel.com/docs/business/getting_started/authentication
 */
class Gateway extends AbstractGateway
{
    public function getName(): string
    {
        return 'Hubtel';
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultParameters(): array
    {
        return ['apiId' => '', 'apiKey' => '', 'merchantAccount' => ''];
    }

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

    /** The "Collection Account Number" (POS Sales ID). */
    public function setMerchantAccount(?string $value): static
    {
        $this->setParameter('merchantAccount', $value);

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
}
