# ivanange/laravel-billing

Billing for Laravel apps that sell **time-based cover** (SMS subscriptions, school terms, memberships) and are often paid **offline**: cash to an agent, mobile money to a merchant number, bank transfer.

- **Any model can be billed**: implement `Billable` and use `HasBilling`.
- **Flexible periods**: a duration (day, week, month, quarter, year × n), a custom date range, or named calendar periods (school terms, seasons).
- **Gateway instances**: several configured accounts of one provider, one active per provider for new payments. Old payments always keep their own instance.
- **Offline payments**: record → (optional maker-checker) confirm → void with reason. Nothing is deleted.
- **Group payments**: one payment covering many billables.
- **Filament 5 plugin**: payments, subscriptions, plans with price presets, calendars, gateways, a *Record payment* action and a *Paid cover* relation manager.
- **Translations** in English, French and German (`billing::billing.*`).

- **Online payments** through [Omnipay](https://github.com/thephpleague/omnipay) drivers (Paystack: cards and Ghana mobile money), with signed webhooks, a self-service pay link and reconciliation. See `docs/DESIGN.md` §12.

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

## Online payments (Paystack)

```bash
composer require omnipay/common paystackhq/omnipay-paystack
```

```php
// config/billing.php
'providers' => [OfflineProvider::class, PaystackProvider::class],
```

1. **Create the gateway.** In Filament, add a gateway with the *Paystack* provider. Paste the secret key (stored encrypted) and tick the methods (card, mobile money, bank transfer).
2. **Point Paystack's webhook at that gateway.** Under Paystack → Settings → API Keys & Webhooks, set the URL to `https://your-app/billing/webhooks/{gateway id}`. Each gateway has its own URL, and its signature is checked with that gateway's own key.
3. **Send pay links.** Use `PayLinkAction::make()` on your billable resource, or `app(PayLinks::class)->make($billable, $plan, $price)`. The payer gets a signed, expiring page: they pick a method, pay on Paystack and come back. The return page asks Paystack for the result; the page's query string is never trusted.
4. **Schedule the reconciliation.** It catches payments whose callback and webhook never arrived:

   ```php
   Schedule::command('billing:reconcile-payments')->everyFifteenMinutes();
   ```

**How an online payment progresses.** It starts as `pending`, with no cover yet. It becomes `confirmed` only once the provider confirms the same reference, amount and currency; that is when the cover is created. Otherwise it ends `failed` (declined, abandoned or mismatched). A confirmed payment can then be `refunded`. Duplicate webhooks and callback/webhook races confirm a payment only once.

**HTTP calls.** Calls to providers go through Laravel's `Http` client, so `Http::fake()` works in your tests.

### NotchPay (Cameroon) and Hubtel (Ghana)

These drivers are built in; only `omnipay/common` is needed. Register `NotchPayProvider::class` and/or `HubtelProvider::class` in `billing.providers`.

- **NotchPay.** Enter the public key, the private key (needed only for refunds) and the webhook *hash key*. Point NotchPay's webhook at `/billing/webhooks/{gateway id}`. Payers enter their phone number and pay with MTN MoMo or Orange Money, in XAF.
- **Hubtel.** Enter the API ID and API key, and set the merchant (collection) account number. **Ask Hubtel to whitelist your server IPs**: the status API that confirms payments rejects other IPs. Hubtel callbacks are unsigned, so the package only treats them as a cue to re-check the status with Hubtel.

**Rate limits** for webhooks and pay pages are keyed by client IP. Behind a load balancer, configure Laravel's trusted proxies so `$request->ip()` is the real client.

**Refund events.** Refunds started from the provider's dashboard are not synced yet; refund from Filament instead.

**Live sandbox tests** (each is skipped unless its keys are set):
- Paystack: `PAYSTACK_TEST_SECRET=sk_test_…`
- NotchPay: `NOTCHPAY_TEST_PUBLIC_KEY=pk_test_…`
- Hubtel: `HUBTEL_TEST_API_ID`, `HUBTEL_TEST_API_KEY`, `HUBTEL_TEST_ACCOUNT`. Hubtel has no public sandbox, so this creates a real, unpaid checkout.

Run them with `vendor/bin/phpunit tests/Live`.

## Moving Kompass

See `docs/KOMPASS_MIGRATION.md`. `php artisan billing:kompass-dry-run --connection=kompass` reports the mapping and writes nothing.

## Events

`PaymentRecorded`, `PaymentConfirmed`, `PaymentFailed`, `PaymentRefunded`, `PaymentVoided`, `CoverageExtended`, `CoverageExpiring`, `CoverageLapsed`, `GatewayActivated`.

## Authorization

Gate abilities `billing.view`, `billing.record`, `billing.confirm`, `billing.void` and `billing.manage` allow any signed-in user unless your app defines them, or you set `billing.authorize_by_default` to `false`.

`billing.refund` sends money back, so it is **denied** unless your app defines it. `billing.record` receives the billable as its argument, so you can scope pay links per record or tenant.

## Configuration

Publish with `php artisan vendor:publish --tag=billing-config`:

- `gateways.one_active_per_provider` (default `true`)
- `confirmation.second_person`, for maker-checker (default `false`)
- `reminders.days_before` (default `3`)
- `proofs.disk`, which must be a private disk (default `local`)

## Tests

```bash
composer install
vendor/bin/phpunit      # unit, feature, Filament panel; live sandbox tests skip without keys
vendor/bin/pint --test
composer analyse        # Larastan level 6
```
