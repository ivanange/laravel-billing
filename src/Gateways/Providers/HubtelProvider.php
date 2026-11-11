<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Providers;

use Illuminate\Http\Request;
use Ivanange\Billing\Contracts\ReceivesWebhooks;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Data\StartedCharge;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\InvalidWebhook;
use Ivanange\Billing\Gateways\Omnipay\Hubtel\Gateway;
use Ivanange\Billing\Gateways\Omnipay\Hubtel\Message\StatusResponse;
use Ivanange\Billing\Gateways\Omnipay\OmnipayProvider;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\SettingField;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Support\Money;
use Omnipay\Common\Message\ResponseInterface;

/**
 * Hubtel Online Checkout (Ghana, GHS) through the package's own thin
 * Omnipay driver.
 *
 * Hubtel callbacks are NOT signed, so a callback is only used as a hint:
 * the handler re-queries the transaction-status API with this instance's
 * credentials and never trusts the posted status or amount.
 *
 * @see https://developers.hubtel.com/docs/business/api_documentation/payment_apis/online_checkout
 */
class HubtelProvider extends OmnipayProvider implements ReceivesWebhooks
{
    /** Hubtel's page offers mobile money (MTN, Telecel, AirtelTigo) and card. */
    public const CHECKOUT = 'hubtel_checkout';

    public static function key(): string
    {
        return 'hubtel';
    }

    public static function label(): string
    {
        return __('billing::billing.providers.hubtel');
    }

    public static function credentialFields(): array
    {
        return [
            new SettingField('api_id', SettingField::SECRET, 'billing::billing.hubtel.api_id', required: true),
            new SettingField('api_key', SettingField::SECRET, 'billing::billing.hubtel.api_key', required: true, helpKey: 'billing::billing.hubtel.api_key_help'),
        ];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('merchant_account', SettingField::TEXT, 'billing::billing.hubtel.merchant_account', required: true, helpKey: 'billing::billing.hubtel.merchant_account_help'),
        ];
    }

    public static function methods(): array
    {
        return [new PaymentMethod(self::CHECKOUT, 'billing::billing.methods.hubtel_checkout')];
    }

    protected function gatewayClass(): string
    {
        return Gateway::class;
    }

    protected function parameters(GatewayInstance $instance): array
    {
        return [
            'apiId' => (string) data_get($instance->credentials, 'api_id', ''),
            'apiKey' => (string) data_get($instance->credentials, 'api_key', ''),
            'merchantAccount' => (string) $instance->setting('merchant_account', ''),
        ];
    }

    public function start(GatewayInstance $instance, PaymentIntent $intent): StartedCharge
    {
        if (strtoupper($intent->currency) !== 'GHS') {
            throw BillingException::because('currency_not_supported', ['currency' => $intent->currency]);
        }

        return parent::start($instance, $intent);
    }

    protected function purchaseParameters(GatewayInstance $instance, PaymentIntent $intent): array
    {
        $parameters = parent::purchaseParameters($instance, $intent);
        unset($parameters['metadata']);

        return $parameters;
    }

    protected function resultFromVerify(string $reference, ResponseInterface $response): ChargeResult
    {
        if (! $response instanceof StatusResponse) {
            return new ChargeResult(ChargeStatus::Pending, $reference);
        }

        $transaction = $response->transaction();
        $amount = $transaction['amount'] ?? null;

        return new ChargeResult(
            status: match (true) {
                $response->isSuccessful() => ChargeStatus::Succeeded,
                $response->isFailed() => ChargeStatus::Failed,
                default => ChargeStatus::Pending,
            },
            reference: $reference,
            amount: is_numeric($amount) ? Money::toMinor($amount, 'GHS') : null,
            // Hubtel documents currencyCode as possibly null; checkout is GHS only.
            currency: $this->stringOrNull($transaction['currencyCode'] ?? null) ?? 'GHS',
            externalId: $response->getTransactionReference(),
            message: $response->getMessage(),
        );
    }

    protected function resultFromRefund(Payment $payment, ResponseInterface $response): ChargeResult
    {
        throw BillingException::because('refund_not_supported');
    }

    /**
     * Unsigned callback: take only the client reference from it, then ask
     * Hubtel for the real status.
     */
    public function parseWebhook(GatewayInstance $instance, Request $request): ?ChargeResult
    {
        $payload = json_decode($request->getContent(), true);
        $reference = is_array($payload) ? ($payload['Data']['ClientReference'] ?? $payload['ClientReference'] ?? null) : null;

        if (! is_string($reference) || ! preg_match('/^[A-Za-z0-9\-]{1,64}$/', $reference)) {
            throw InvalidWebhook::payload();
        }

        // Only re-query references this instance actually issued: an
        // attacker can't make us call Hubtel for arbitrary strings.
        $known = Payment::query()
            ->where('gateway_instance_id', $instance->getKey())
            ->where('reference', $reference)
            ->exists();

        return $known ? $this->verify($instance, $reference) : null;
    }
}
