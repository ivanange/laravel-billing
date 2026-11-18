<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Paystack;

use Omnipay\Common\Message\RequestInterface;
use Omnipay\Paystack\Gateway;

/**
 * The official Paystack Omnipay driver (paystackhq/omnipay-paystack), with
 * two additions: purchase() can limit the checkout to the payer's chosen
 * channel (card, mobile_money, bank_transfer), which the upstream request
 * does not send; refund() keeps the HTTP status of Paystack's answer.
 */
class PaystackGateway extends Gateway
{
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
    public function refund(array $parameters = []): RequestInterface
    {
        return $this->createRequest(RefundRequest::class, $parameters);
    }
}
