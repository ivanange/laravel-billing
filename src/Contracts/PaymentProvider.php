<?php

declare(strict_types=1);

namespace Ivanange\Billing\Contracts;

use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\SettingField;

/**
 * One kind of payment provider (offline, Paystack, NotchPay...). Admins
 * create any number of GatewayInstances of it, each with its own
 * credentials and settings. Online providers (phase 2) will add
 * capability interfaces on top: ChargesOnce, ReceivesWebhooks, Refunds.
 */
interface PaymentProvider
{
    /** Stable key stored on gateway instances, e.g. "offline". */
    public static function key(): string;

    public static function label(): string;

    /**
     * Secret values (API keys). Stored encrypted, never shown back.
     *
     * @return list<SettingField>
     */
    public static function credentialFields(): array;

    /**
     * Non-secret options (instructions, toggles).
     *
     * @return list<SettingField>
     */
    public static function settingFields(): array;

    /**
     * Every method this provider can offer; each instance enables a subset.
     *
     * @return list<PaymentMethod>
     */
    public static function methods(): array;
}
