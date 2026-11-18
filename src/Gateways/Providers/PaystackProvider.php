<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Providers;

use Illuminate\Http\Request;
use Ivanange\Billing\Contracts\ReceivesWebhooks;
use Ivanange\Billing\Contracts\Refunds;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Exceptions\GatewayOutcomeUnknown;
use Ivanange\Billing\Exceptions\InvalidWebhook;
use Ivanange\Billing\Gateways\Omnipay\OmnipayProvider;
use Ivanange\Billing\Gateways\Omnipay\Paystack\PaystackGateway;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\SettingField;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Omnipay\Common\Message\ResponseInterface;

/**
 * Paystack (cards, Ghana mobile money: MTN, Telecel, AirtelTigo; bank
 * transfer) through the official Omnipay driver.
 *
 * @see https://paystack.com/docs/api/transaction/
 * @see https://paystack.com/docs/payments/webhooks/
 */
class PaystackProvider extends OmnipayProvider implements ReceivesWebhooks, Refunds
{
    public const CARD = 'card';

    public const MOBILE_MONEY = 'mobile_money';

    public const BANK_TRANSFER = 'bank_transfer';

    /** Final non-success statuses. "abandoned" is not final: the payer may still be approving a mobile money prompt. */
    private const FAILED = ['failed', 'reversed'];

    public static function key(): string
    {
        return 'paystack';
    }

    public static function label(): string
    {
        return __('billing::billing.providers.paystack');
    }

    public static function credentialFields(): array
    {
        return [
            new SettingField('secret_key', SettingField::SECRET, 'billing::billing.paystack.secret_key', required: true, helpKey: 'billing::billing.paystack.secret_key_help'),
        ];
    }

    public static function settingFields(): array
    {
        return [];
    }

    public static function methods(): array
    {
        return array_map(
            fn (string $key): PaymentMethod => new PaymentMethod($key, "billing::billing.methods.online_{$key}"),
            [self::CARD, self::MOBILE_MONEY, self::BANK_TRANSFER],
        );
    }

    public static function payerFields(): array
    {
        return ['email'];
    }

    protected function gatewayClass(): string
    {
        return PaystackGateway::class;
    }

    protected function parameters(GatewayInstance $instance): array
    {
        return ['secret_key' => (string) data_get($instance->credentials, 'secret_key', '')];
    }

    protected function purchaseParameters(GatewayInstance $instance, PaymentIntent $intent): array
    {
        return parent::purchaseParameters($instance, $intent) + ['channels' => [$intent->method]];
    }

    /**
     * @see https://paystack.com/docs/api/transaction/#verify
     */
    protected function resultFromVerify(string $reference, ResponseInterface $response): ChargeResult
    {
        $data = (array) data_get((array) $response->getData(), 'data', []);

        return $this->toResult($reference, $data, $response->getMessage());
    }

    /**
     * Only two answers are decisions: a 2xx saying {"status": true}
     * (success), or a 4xx other than 408 saying {"status": false} (refusal).
     * Anything else, a 2xx saying false included, may hide a refund
     * Paystack already queued.
     *
     * @see https://paystack.com/docs/api/refund/#create
     */
    protected function resultFromRefund(Payment $payment, ResponseInterface $response): ChargeResult
    {
        $data = (array) $response->getData();
        $httpStatus = (int) ($data['httpStatus'] ?? 0);
        $accepted = $httpStatus >= 200 && $httpStatus < 300 && ($data['status'] ?? null) === true;
        $refused = ! ($httpStatus >= 200 && $httpStatus < 300) && ($data['status'] ?? null) === false;

        if ($this->outcomeUnknown($httpStatus) || ! ($accepted || $refused)) {
            throw GatewayOutcomeUnknown::from(sprintf('Paystack refund: HTTP %d, %s', $httpStatus, $this->stringOrNull($data['message'] ?? null) ?? 'no readable answer'));
        }

        return new ChargeResult(
            status: $accepted ? ChargeStatus::Succeeded : ChargeStatus::Failed,
            reference: (string) $payment->reference,
            amount: $this->intOrNull(data_get($data, 'data.amount')),
            currency: $this->stringOrNull(data_get($data, 'data.currency')),
            externalId: $this->stringOrNull(data_get($data, 'data.id')),
            message: $this->stringOrNull(data_get($data, 'message')),
        );
    }

    /**
     * Paystack signs the raw body with the account's secret key:
     * x-paystack-signature = HMAC-SHA512(body, secret_key), hex.
     *
     * @see https://paystack.com/docs/payments/webhooks/#verify-event-origin
     */
    public function parseWebhook(GatewayInstance $instance, Request $request): ?ChargeResult
    {
        $secret = (string) data_get($instance->credentials, 'secret_key', '');
        $signature = (string) $request->header('x-paystack-signature', '');
        $body = $request->getContent();

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha512', $body, $secret), $signature)) {
            throw InvalidWebhook::signature();
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            throw InvalidWebhook::payload();
        }

        if (($payload['event'] ?? null) !== 'charge.success') {
            return null;
        }

        $data = (array) ($payload['data'] ?? []);
        $reference = $this->stringOrNull($data['reference'] ?? null);

        return $reference === null ? null : $this->toResult($reference, $data, null);
    }

    /**
     * @param  array<string, mixed>  $data  a Paystack transaction object
     */
    private function toResult(string $reference, array $data, ?string $message): ChargeResult
    {
        $status = (string) ($data['status'] ?? '');

        return new ChargeResult(
            status: match (true) {
                $status === 'success' => ChargeStatus::Succeeded,
                in_array($status, self::FAILED, true) => ChargeStatus::Failed,
                default => ChargeStatus::Pending,
            },
            // Only the provider's own reference counts: falling back to the one we
            // asked about would make the settler's reference check meaningless.
            reference: $this->stringOrNull($data['reference'] ?? null) ?? '',
            amount: $this->intOrNull($data['amount'] ?? null),
            currency: $this->stringOrNull($data['currency'] ?? null),
            externalId: $this->stringOrNull($data['id'] ?? null),
            message: $this->stringOrNull($data['gateway_response'] ?? null) ?? $message,
        );
    }
}
