<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Providers;

use Illuminate\Http\Request;
use Ivanange\Billing\Contracts\ReceivesWebhooks;
use Ivanange\Billing\Contracts\Refunds;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Exceptions\InvalidWebhook;
use Ivanange\Billing\Gateways\Omnipay\NotchPay\Gateway;
use Ivanange\Billing\Gateways\Omnipay\NotchPay\Message\TransactionResponse;
use Ivanange\Billing\Gateways\Omnipay\OmnipayProvider;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\SettingField;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Omnipay\Common\Message\ResponseInterface;

/**
 * NotchPay: Cameroon mobile money (MTN MoMo, Orange Money) in XAF, through
 * the package's own thin Omnipay driver.
 *
 * @see https://developer.notchpay.co/api-reference/payments.md
 * @see https://developer.notchpay.co/get-started/webhooks/verify.md
 */
class NotchPayProvider extends OmnipayProvider implements ReceivesWebhooks, Refunds
{
    public const MTN = 'mtn_momo';

    public const ORANGE = 'orange_money';

    /** Our method key => NotchPay locked_channel. */
    private const CHANNELS = [self::MTN => 'cm.mtn', self::ORANGE => 'cm.orange'];

    /** Webhook events that settle a payment one way or the other. */
    private const EVENTS = [
        'payment.complete' => ChargeStatus::Succeeded,
        'payment.failed' => ChargeStatus::Failed,
        'payment.canceled' => ChargeStatus::Failed,
        'payment.expired' => ChargeStatus::Failed,
    ];

    public static function key(): string
    {
        return 'notchpay';
    }

    public static function label(): string
    {
        return __('billing::billing.providers.notchpay');
    }

    public static function credentialFields(): array
    {
        return [
            new SettingField('public_key', SettingField::SECRET, 'billing::billing.notchpay.public_key', required: true, helpKey: 'billing::billing.notchpay.public_key_help'),
            new SettingField('private_key', SettingField::SECRET, 'billing::billing.notchpay.private_key', helpKey: 'billing::billing.notchpay.private_key_help'),
            new SettingField('hash_key', SettingField::SECRET, 'billing::billing.notchpay.hash_key', required: true, helpKey: 'billing::billing.notchpay.hash_key_help'),
        ];
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function methods(): array
    {
        return [
            new PaymentMethod(self::MTN, 'billing::billing.methods.mtn_momo'),
            new PaymentMethod(self::ORANGE, 'billing::billing.methods.orange_money'),
        ];
    }

    public static function payerFields(): array
    {
        return ['phone'];
    }

    protected function gatewayClass(): string
    {
        return Gateway::class;
    }

    protected function parameters(GatewayInstance $instance): array
    {
        return [
            'publicKey' => (string) data_get($instance->credentials, 'public_key', ''),
            'privateKey' => (string) data_get($instance->credentials, 'private_key', ''),
        ];
    }

    protected function purchaseParameters(GatewayInstance $instance, PaymentIntent $intent): array
    {
        $parameters = parent::purchaseParameters($instance, $intent);
        unset($parameters['metadata'], $parameters['notifyUrl']);

        return $parameters + ['channel' => self::CHANNELS[$intent->method] ?? null];
    }

    protected function resultFromVerify(string $reference, ResponseInterface $response): ChargeResult
    {
        if (! $response instanceof TransactionResponse) {
            return new ChargeResult(ChargeStatus::Pending, $reference);
        }

        $transaction = $response->transaction();

        return new ChargeResult(
            status: match (true) {
                $response->isSuccessful() => ChargeStatus::Succeeded,
                $response->isFailed() => ChargeStatus::Failed,
                default => ChargeStatus::Pending,
            },
            reference: $reference,
            amount: $this->intOrNull($transaction['amount'] ?? null),
            currency: $this->stringOrNull($transaction['currency'] ?? null),
            externalId: $this->stringOrNull($transaction['id'] ?? null),
            message: $response->getMessage(),
        );
    }

    protected function resultFromRefund(Payment $payment, ResponseInterface $response): ChargeResult
    {
        return new ChargeResult(
            status: $response->isSuccessful() ? ChargeStatus::Succeeded : ChargeStatus::Failed,
            reference: (string) $payment->reference,
            externalId: $this->stringOrNull($response->getTransactionReference()),
            message: $response->getMessage(),
        );
    }

    /**
     * x-notch-signature = hex HMAC-SHA256 of the raw body, keyed with the
     * account's webhook "hash key" (not the API keys).
     *
     * @see https://developer.notchpay.co/get-started/webhooks/verify.md
     */
    public function parseWebhook(GatewayInstance $instance, Request $request): ?ChargeResult
    {
        $secret = (string) data_get($instance->credentials, 'hash_key', '');
        $signature = (string) $request->header('x-notch-signature', '');
        $body = $request->getContent();

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $body, $secret), strtolower($signature))) {
            throw InvalidWebhook::signature();
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            throw InvalidWebhook::payload();
        }

        $status = self::EVENTS[(string) ($payload['type'] ?? $payload['event'] ?? '')] ?? null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        // Our reference comes back as "trxref" when NotchPay generated its own
        // "reference" (trx.…); either may be stored on the payment.
        $reference = $this->stringOrNull($data['trxref'] ?? null) ?? $this->stringOrNull($data['reference'] ?? null);

        if ($status === null || $reference === null) {
            return null;
        }

        return new ChargeResult(
            status: $status,
            reference: $reference,
            amount: $this->intOrNull($data['amount'] ?? null),
            currency: $this->stringOrNull($data['currency'] ?? null),
            externalId: $this->stringOrNull($data['id'] ?? null),
        );
    }
}
