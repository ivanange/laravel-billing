<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Paystack;

use Omnipay\Paystack\Gateway;

/**
 * The official Paystack Omnipay driver (paystackhq/omnipay-paystack), with
 * one addition: purchase() can limit the checkout to the payer's chosen
 * channel (card, mobile_money, bank_transfer). The upstream request does
 * not send "channels".
 */
class PaystackGateway extends Gateway
{
    public function purchase(array $parameters = [])
    {
        return $this->createRequest(PurchaseRequest::class, $parameters);
    }
}
