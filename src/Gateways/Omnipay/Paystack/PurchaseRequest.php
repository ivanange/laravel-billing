<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay\Paystack;

use Omnipay\Paystack\Message\PurchaseRequest as BasePurchaseRequest;

/**
 * Adds Paystack's documented "channels" field to transaction/initialize.
 *
 * @see https://paystack.com/docs/api/transaction/#initialize
 */
class PurchaseRequest extends BasePurchaseRequest
{
    /**
     * @param  list<string>|null  $value
     */
    public function setChannels(?array $value): static
    {
        $this->setParameter('channels', $value);

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getChannels(): ?array
    {
        return $this->getParameter('channels');
    }

    public function getData()
    {
        $data = parent::getData();

        if ($this->getChannels()) {
            $data['channels'] = $this->getChannels();
        }

        return $data;
    }
}
