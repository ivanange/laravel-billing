<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Paystack API response bodies as documented at
 * https://paystack.com/docs/api/transaction/ (shapes trimmed to the
 * fields the driver and our provider read).
 */
trait PaystackFakes
{
    protected function paystackInitialized(string $reference = 'BIL-TEST'): PromiseInterface
    {
        return Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/0peioxfhpn',
                'access_code' => '0peioxfhpn',
                'reference' => $reference,
            ],
        ]);
    }

    protected function paystackTransaction(string $reference, string $status = 'success', int $amount = 1500, string $currency = 'GHS'): PromiseInterface
    {
        return Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'id' => 4099260516,
                'status' => $status,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'gateway_response' => $status === 'success' ? 'Approved' : 'Declined',
                'channel' => 'mobile_money',
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: string} body and its signature
     */
    protected function paystackWebhook(string $secret, string $reference, int $amount = 1500, string $currency = 'GHS', string $event = 'charge.success'): array
    {
        $body = (string) json_encode([
            'event' => $event,
            'data' => [
                'id' => 4099260516,
                'status' => 'success',
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency,
                'gateway_response' => 'Approved',
            ],
        ]);

        return [$body, hash_hmac('sha512', $body, $secret)];
    }
}
