# ivanange/laravel-billing

Billing for Laravel apps that sell **time-based cover** (SMS subscriptions, school terms, memberships) and are often paid **offline**: cash to an agent, mobile money to a merchant number, bank transfer.

- **Any model can be billed**: implement `Billable` and use `HasBilling`.
- **Flexible periods**: a duration (day, week, month, quarter, year × n), a custom date range, or named calendar periods (school terms, seasons).
- **Gateway instances**: several configured accounts of one provider, one active per provider for new payments. Old payments always keep their own instance.
- **Offline payments**: record → (optional maker-checker) confirm → void with reason. Nothing is deleted.
- **Group payments**: one payment covering many billables.
- **Filament 5 plugin**: payments, subscriptions, plans with price presets, calendars, gateways, a *Record payment* action and a *Paid cover* relation manager.
- **Translations** in English, French and German (`billing::billing.*`).

Online gateways (through [Omnipay](https://github.com/thephpleague/omnipay) drivers) come in phase 2; see `docs/DESIGN.md`.

## Install

```bash
composer require ivanange/laravel-billing
php artisan migrate
```

```php
use Ivanange\Billing\Concerns\HasBilling;
use Ivanange\Billing\Contracts\Billable;

class Farmer extends Model implements Billable
{
    use HasBilling;
}

Farmer::query()->covered()->get();          // paid up today (grace days included)
$farmer->isCovered(now()->addWeek());
$farmer->paidUntil();
```

Filament:

```php
$panel->plugin(BillingPlugin::make()->billables([Farmer::class => 'Farmers']));

// In your billable resource:
RecordPaymentAction::make();               // row or header action
RecordGroupPaymentBulkAction::make();      // bulk action
CoveragePeriodsRelationManager::class;     // in getRelations()
```

Schedule the daily check, which fires `CoverageExpiring` and `CoverageLapsed` once per cover end:

```php
Schedule::command('billing:check-coverage')->dailyAt('08:00');
```

## Recording a payment in code

```php
app(PaymentRecorder::class)->record(new PaymentDraft(
    billables: [$farmer],
    plan: $plan,
    term: new DurationTerm(DurationUnit::Month, 3),
    gateway: $cashOffice,
    method: OfflineProvider::CASH,
    amountPerBillable: 1500,            // minor units (pesewas)
    reference: 'RCPT-0042',
), auth()->user());
```

Rules enforced by the service: an active gateway that offers the method and currency, the plan accepts the billable, no overlapping cover (unless `allowOverlap`), no duplicate reference per gateway, required reference or proof when the gateway asks for it, and amount 0 only for `complimentary`.

## Events

`PaymentRecorded`, `PaymentConfirmed`, `PaymentVoided`, `CoverageExtended`, `CoverageExpiring`, `CoverageLapsed`, `GatewayActivated`.

## Authorization

Gate abilities `billing.view`, `billing.record`, `billing.confirm`, `billing.void` and `billing.manage`. They allow any signed-in user unless your app defines them (or you set `billing.authorize_by_default` to `false`).

## Configuration

Publish with `php artisan vendor:publish --tag=billing-config`:

- `gateways.one_active_per_provider` (default `true`)
- `confirmation.second_person`, for maker-checker (default `false`)
- `reminders.days_before` (default `3`)
- `proofs.disk`, which must be a private disk (default `local`)

## Tests

```bash
composer install
vendor/bin/phpunit
```
