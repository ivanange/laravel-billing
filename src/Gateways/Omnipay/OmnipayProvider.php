<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Ivanange\Billing\Contracts\ChargesOnce;
use Ivanange\Billing\Contracts\PaymentProvider;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Data\StartedCharge;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Exceptions\GatewayOutcomeUnknown;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Support\Money;
use LogicException;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Exception\InvalidRequestException;
use Omnipay\Common\GatewayInterface;
use Omnipay\Common\Http\Exception as HttpException;
use Omnipay\Common\Http\PsrClient;
use Omnipay\Common\Message\RedirectResponseInterface;
use Omnipay\Common\Message\RequestInterface;
use Omnipay\Common\Message\ResponseInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * Generic adapter from our capability interfaces to any Omnipay gateway.
 *
 * The Omnipay gateway object is built per call from the GatewayInstance
 * passed in, which is always the payment's own instance. Credentials are
 * never looked up by provider type, so switching the active Paystack
 * account never changes which keys verify or refund an older payment.
 *
 * A subclass declares the Omnipay gateway class, its fields and methods,
 * and how a verify response maps to a ChargeResult. Subclasses whose
 * driver can refund add `implements Refunds` (refund() is shared here).
 */
abstract class OmnipayProvider implements ChargesOnce, PaymentProvider
{
    /**
     * @return class-string<GatewayInterface>
     */
    abstract protected function gatewayClass(): string;

    abstract protected function resultFromVerify(string $reference, ResponseInterface $response): ChargeResult;

    abstract protected function resultFromRefund(Payment $payment, ResponseInterface $response): ChargeResult;

    /**
     * What the payer must enter on the pay page for this provider.
     *
     * @return list<'email'|'phone'>
     */
    public static function payerFields(): array
    {
        return [];
    }

    /**
     * Omnipay initialize() parameters for one instance. Override to map
     * credential/setting names onto the driver's parameter names.
     *
     * @return array<string, mixed>
     */
    protected function parameters(GatewayInstance $instance): array
    {
        return (array) $instance->credentials;
    }

    /**
     * @return array<string, mixed>
     */
    protected function purchaseParameters(GatewayInstance $instance, PaymentIntent $intent): array
    {
        return array_filter([
            'amountInteger' => $intent->amount,
            'currency' => $intent->currency,
            'transactionReference' => $intent->reference,
            'transactionId' => $intent->reference,
            'returnUrl' => $intent->returnUrl,
            'description' => $intent->description,
            'email' => $intent->email,
            'phone' => $intent->phone,
            'metadata' => $intent->metadata,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** The reference the provider knows the payment by, for refunds. */
    protected function refundReference(Payment $payment): string
    {
        return (string) $payment->reference;
    }

    /**
     * @return array<string, mixed>
     */
    protected function verifyParameters(GatewayInstance $instance, string $reference): array
    {
        return ['transactionReference' => $reference];
    }

    public function gateway(GatewayInstance $instance): GatewayInterface
    {
        if (! interface_exists(GatewayInterface::class)) {
            throw BillingException::because('omnipay_missing');
        }

        if ($instance->provider !== static::key()) {
            throw new LogicException("Gateway instance {$instance->id} belongs to [{$instance->provider}], not [".static::key().'].');
        }

        $client = new PsrClient(new LaravelHttpClient(
            app(Factory::class),
            (int) config('billing.online.http_timeout', 30),
        ));

        $class = $this->gatewayClass();
        $gateway = new $class($client);
        $gateway->initialize($this->parameters($instance));

        return $gateway;
    }

    public function start(GatewayInstance $instance, PaymentIntent $intent): StartedCharge
    {
        $response = $this->send(fn (): RequestInterface => $this->gateway($instance)
            ->purchase($this->purchaseParameters($instance, $intent)));

        if (! $response instanceof RedirectResponseInterface || ! $response->isRedirect() || ! $response->getRedirectUrl()) {
            throw GatewayError::from($response->getMessage());
        }

        return new StartedCharge(
            redirectUrl: (string) $response->getRedirectUrl(),
            reference: $intent->reference,
            externalId: $this->stringOrNull($response->getTransactionReference()),
        );
    }

    public function verify(GatewayInstance $instance, string $reference): ChargeResult
    {
        $response = $this->send(fn (): RequestInterface => $this->gateway($instance)
            ->completePurchase($this->verifyParameters($instance, $reference)));

        return $this->resultFromVerify($reference, $response);
    }

    public function refund(GatewayInstance $instance, Payment $payment, ?int $amount = null): ChargeResult
    {
        $gateway = $this->gateway($instance);

        if (! $gateway instanceof AbstractGateway || ! $gateway->supportsRefund()) {
            throw BillingException::because('refund_not_supported');
        }

        $response = $this->send(fn (): RequestInterface => $gateway->refund(array_filter([
            'transactionReference' => $this->refundReference($payment),
            'amountInteger' => $amount,
            'currency' => $payment->currency,
        ], fn (mixed $value): bool => $value !== null)), movesMoney: true);

        return $this->resultFromRefund($payment, $response);
    }

    /**
     * With $movesMoney, any error except Omnipay's own parameter check (which
     * runs before anything is sent) is an unknown outcome: a broken 4xx
     * transfer or a driver failing on the answer may follow a refund the
     * provider already made. Some drivers (Paystack's) wrap HTTP errors in
     * InvalidRequestException too, so one carrying an HTTP error is unknown.
     *
     * @param  callable(): RequestInterface  $request
     */
    protected function send(callable $request, bool $movesMoney = false): ResponseInterface
    {
        try {
            return $request()->send();
        } catch (BillingException|LogicException $e) {
            throw $e;
        } catch (Throwable $e) {
            $unknown = self::lostInTransit($e) || ($movesMoney && ! self::neverSent($e));

            throw $unknown
                ? GatewayOutcomeUnknown::from($e->getMessage(), $e)
                : GatewayError::from($e->getMessage(), $e);
        }
    }

    /**
     * A timeout or dropped connection, however deep the drivers wrapped it:
     * the provider may have acted on the request.
     */
    private static function lostInTransit(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof NetworkExceptionInterface || $current instanceof ConnectionException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Omnipay's parameter check failed before the HTTP client was reached.
     */
    private static function neverSent(Throwable $e): bool
    {
        if (! $e instanceof InvalidRequestException) {
            return false;
        }

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof HttpException) {
                return false;
            }
        }

        return true;
    }

    /**
     * A 5xx or 408 answer says nothing about whether the request took effect.
     */
    protected function outcomeUnknown(int $httpStatus): bool
    {
        return $httpStatus >= 500 || $httpStatus === 408 || $httpStatus < 100;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Whole numbers only: "5000.00" and 5000.0 are 5000, but "5000.9" is not
     * an amount we can compare, so it becomes null (a mismatch), never 5000.
     */
    protected function intOrNull(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => is_finite($value) && floor($value) === $value ? (int) $value : null,
            is_string($value) && preg_match('/^-?\d{1,18}(\.0+)?$/', trim($value)) === 1 => (int) $value,
            default => null,
        };
    }

    /**
     * A major-unit amount (e.g. cedis) in minor units, or null when it has
     * more decimals than the currency allows: rounding it could fake a match.
     */
    protected function minorOrNull(mixed $major, string $currency): ?int
    {
        if (! is_numeric($major)) {
            return null;
        }

        $scaled = (float) $major * (10 ** Money::decimals($currency));

        return $scaled >= 0 && abs($scaled - round($scaled)) < 1e-6 ? (int) round($scaled) : null;
    }
}
