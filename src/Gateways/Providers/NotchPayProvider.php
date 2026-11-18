<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Providers;

use Illuminate\Http\Request;
use Ivanange\Billing\Contracts\ReceivesWebhooks;
use Ivanange\Billing\Contracts\Refunds;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Exceptions\GatewayOutcomeUnknown;
use Ivanange\Billing\Exceptions\InvalidWebhook;
use Ivanange\Billing\Gateways\Omnipay\NotchPay\Gateway;
use Ivanange\Billing\Gateways\Omnipay\NotchPay\Message\AbstractRequest;
use Ivanange\Billing\Gateways\Omnipay\NotchPay\Message\TransactionResponse;
use Ivanange\Billing\Gateways\Omnipay\OmnipayProvider;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\SettingField;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Omnipay\Common\Message\RequestInterface;
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
        unset($parameters['metadata']);

        return $parameters + ['channel' => self::CHANNELS[$intent->method] ?? null];
    }

    protected function resultFromVerify(string $reference, ResponseInterface $response): ChargeResult
    {
        if (! $response instanceof TransactionResponse) {
            return new ChargeResult(ChargeStatus::Pending, $reference);
        }

        $transaction = $response->transaction();

        // $reference is already '' when verify() found the answer is about another payment.
        return new ChargeResult(
            status: match (true) {
                $response->isSuccessful() => ChargeStatus::Succeeded,
                $response->isFailed() => ChargeStatus::Failed,
                default => ChargeStatus::Pending,
            },
            reference: $reference,
            amount: $this->intOrNull($transaction['amount'] ?? null),
            currency: $this->stringOrNull($transaction['currency'] ?? null),
            externalId: $this->providerReference($transaction, $reference),
            message: $response->getMessage(),
        );
    }

    /**
     * NotchPay's own reference (trx.…) when it differs from ours, else its id.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function providerReference(array $transaction, string $ours): ?string
    {
        $theirs = $this->stringOrNull($transaction['reference'] ?? null);

        return $theirs !== null && $theirs !== $ours ? $theirs : $this->stringOrNull($transaction['id'] ?? null);
    }

    /**
     * Ask NotchPay with its own reference (trx.…) when we stored one at
     * checkout: that one is documented to work, while lookups by the
     * merchant's reference are not.
     *
     * The answer must name the payment we asked about: our reference (as
     * "reference" or "trxref"), or the trx.… reference we looked it up by.
     * Otherwise the result carries an empty reference and confirms nothing.
     */
    public function verify(GatewayInstance $instance, string $reference): ChargeResult
    {
        $scoped = Payment::query()->where('gateway_instance_id', $instance->getKey());
        $payment = (clone $scoped)->where('reference', $reference)->first()
            ?? (clone $scoped)->where('external_id', $reference)->first();

        $stored = $payment?->external_id;
        $ours = $payment !== null ? (string) $payment->reference : (self::isNotchPayReference($reference) ? null : $reference);
        $theirs = match (true) {
            is_string($stored) && self::isNotchPayReference($stored) => $stored,
            self::isNotchPayReference($reference) => $reference,
            default => null,
        };

        $response = $this->send(fn (): RequestInterface => $this->gateway($instance)
            ->completePurchase($this->verifyParameters($instance, $theirs ?? (string) $ours)));

        $transaction = $response instanceof TransactionResponse ? $response->transaction() : [];
        $named = array_filter([$this->stringOrNull($transaction['reference'] ?? null), $this->stringOrNull($transaction['trxref'] ?? null)]);
        $matches = ($ours !== null && in_array($ours, $named, true))
            || ($theirs !== null && $this->stringOrNull($transaction['reference'] ?? null) === $theirs);

        return $this->resultFromVerify($matches ? $reference : '', $response);
    }

    private static function isNotchPayReference(string $value): bool
    {
        return str_starts_with($value, 'trx.');
    }

    protected function refundReference(Payment $payment): string
    {
        return $payment->external_id !== null && self::isNotchPayReference($payment->external_id) ? $payment->external_id : (string) $payment->reference;
    }

    /**
     * Only two answers are decisions: a 2xx carrying a refund object that has
     * not failed (success), or a readable 4xx other than 408 (refusal). A 2xx
     * is never a refusal: "202 Accepted" without a refund object, or a
     * refund object marked failed, may hide a refund already made.
     */
    protected function resultFromRefund(Payment $payment, ResponseInterface $response): ChargeResult
    {
        $data = (array) $response->getData();
        $httpStatus = (int) ($data['httpStatus'] ?? 0);
        $readableRefusal = ! AbstractRequest::succeeded($data) && (is_array($data['refund'] ?? null) || is_string($data['message'] ?? null));

        if ($this->outcomeUnknown($httpStatus) || ! ($response->isSuccessful() || $readableRefusal)) {
            throw GatewayOutcomeUnknown::from(sprintf('NotchPay refund: HTTP %d, %s', $httpStatus, $response->getMessage() ?? 'no readable answer'));
        }

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

        // A success is only taken from NotchPay's status API, never from the
        // webhook body: the documented payload shape doesn't guarantee amount
        // and currency, and a missing one would fail a payment that was paid.
        if ($status === ChargeStatus::Succeeded) {
            return $this->verifiedOrPending($instance, $reference);
        }

        return new ChargeResult(
            status: $status,
            reference: $reference,
            amount: $this->intOrNull($data['amount'] ?? null),
            currency: $this->stringOrNull($data['currency'] ?? null),
            externalId: $this->providerReference($data, $reference),
        );
    }

    private function verifiedOrPending(GatewayInstance $instance, string $reference): ChargeResult
    {
        try {
            return $this->verify($instance, $reference);
        } catch (GatewayError) {
            // Status API down: leave it pending; billing:reconcile-payments retries.
            return new ChargeResult(ChargeStatus::Pending, $reference);
        }
    }
}
