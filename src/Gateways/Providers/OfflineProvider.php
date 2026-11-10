<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Providers;

use Ivanange\Billing\Contracts\ManualConfirmation;
use Ivanange\Billing\Contracts\PaymentProvider;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\SettingField;

/**
 * Money handled outside the app (cash, a transfer, mobile money sent to a
 * merchant number) and recorded by staff.
 */
class OfflineProvider implements ManualConfirmation, PaymentProvider
{
    public const CASH = 'cash';

    public const BANK_TRANSFER = 'bank_transfer';

    public const MOBILE_MONEY = 'mobile_money';

    public const CHEQUE = 'cheque';

    /** Free cover (pilots, sponsorships), recorded with amount 0 for the audit trail. */
    public const COMPLIMENTARY = 'complimentary';

    public static function key(): string
    {
        return 'offline';
    }

    public static function label(): string
    {
        return __('billing::billing.providers.offline');
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function settingFields(): array
    {
        return [
            new SettingField('instructions', SettingField::TEXTAREA, 'billing::billing.offline.instructions', helpKey: 'billing::billing.offline.instructions_help'),
            new SettingField('require_reference', SettingField::TOGGLE, 'billing::billing.offline.require_reference', default: false),
            new SettingField('require_proof', SettingField::TOGGLE, 'billing::billing.offline.require_proof', default: false),
        ];
    }

    public static function methods(): array
    {
        return array_map(
            fn (string $key): PaymentMethod => new PaymentMethod($key, "billing::billing.methods.{$key}"),
            [self::CASH, self::MOBILE_MONEY, self::BANK_TRANSFER, self::CHEQUE, self::COMPLIMENTARY],
        );
    }
}
