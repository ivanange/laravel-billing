# Billing package: design (draft for review)

Status: **approved 2026-11-10; phase 1 done (v0.1.0); phase 2 (Paystack) and phase 3 (NotchPay, Hubtel, Kompass plan) implemented, see §12** as `ivanange/laravel-billing` (namespace `Ivanange\Billing`). Written from a study of the Kompass billing code and a survey of existing packages. Once the package repository is pushed, this file moves there.

## 0. What I understood you asked for (please correct)

1. The package lives in **your personal GitHub account**, not the MacoopA organisation, and is installed into apps through Composer.
2. **Don't integrate payment SDKs ourselves.** Sit on an existing package that already gives one unified API over many providers. Our value is the layer on top:
   - admins create **payment driver instances**, including several instances of the same provider with different credentials and options, and choose **which one is active for each provider** (one at a time);
   - each instance turns specific **payment methods** on;
   - users choose from the active instances and methods when they subscribe or pay.
3. A **Billable** contract that any model can implement, so the same package serves different uses: farmers' SMS, school fees by term, tutoring (Kompass), quarterly memberships, and so on.
4. **Flexible periods:** a month, a quarter, a year, a school term, or any custom date range.
5. **Translations** built in.
6. It must be able to **replace Kompass's billing code later**. Kompass already supports several driver rows per provider, but that part doesn't really work (see §2), so this design generalises it carefully.
7. **Phase 1 is offline payments only.** Online gateways come later through the unified layer.

## 1. Build vs reuse

| Need | Existing option | Verdict |
|---|---|---|
| Plans, subscriptions, periods | `laravelcm/laravel-subscriptions` 1.8 (supports Laravel 13; its README says "Payments are out of scope"); `rinvex` (abandoned); `soulbscription` (Laravel 12 at most) | Fixed intervals only, no payment records, no offline flow. **Don't use.** We model the periods ourselves; it's small. |
| Unified gateway API | **`omnipay/common` 3.5.1** (2026-02, 22M installs, no Laravel dependency, configured **at runtime** per gateway object) | **Use as the gateway layer.** Runtime config is what makes several instances per provider possible. |
| | `payum/payum` (active, 2.0 alpha; its Laravel bridge stopped at Laravel 8; no African gateways) | Fallback if Omnipay stalls. |
| | `payzephyr` (2025, ~1k installs, config is **static**: one block per provider name) | Too young, and static config rules out several instances per provider. |
| Provider drivers | Omnipay drivers: Stripe (last release 2022), PayPal (2018, legacy APIs), Paystack (official, 2026-10); for Flutterwave, MTN MoMo, Orange, Hubtel, NotchPay, CinetPay none exist or they are toys | Use existing drivers as they are (Paystack also covers Ghana mobile money: MTN, Telecel, AirtelTigo). Only where **no Omnipay driver exists** (NotchPay for Cameroon MTN/Orange, Hubtel) we write one: a small class implementing Omnipay's own gateway interface over the provider's HTTP API, so it plugs into the same path as the others. Nothing extra is added on top of Omnipay for providers that already have a driver. |
| Recurring and webhooks | Not part of Omnipay's core | **Our package** defines these capabilities. For mobile money, "recurring" means scheduled reminders plus one-off charges. |

So the package owns: driver instances and methods, billables, plans and periods, coverage, payment records, the offline flow, the Filament UI and translations. Omnipay owns talking to gateways.

## 2. Lessons from Kompass (what to keep, what to fix)

Keep:
- the subscription → billing-cycle model, with explicit `period_start`/`period_end` rows;
- staff confirming manual payments, with a proof upload and `confirmed_by`;
- reminders and suspension for unpaid periods ("dunning");
- events such as `BillingCyclePaid`;
- the encrypted `config` on each driver row;
- the payer being separate from the beneficiary (`payer_user_id`).

Fix while generalising:

| Kompass problem | Package rule |
|---|---|
| Drivers are resolved **by type**: `PaymentDriverManager::driver($type)` takes the first active row, and saving one row deactivates the others of that type. A subscription's `payment_driver_id` doesn't decide which credentials are used. | Keep **one active instance per provider** (switchable off in config), but switch it with an explicit *Activate* action, not a hidden save hook. "Active" only decides what is **offered for new payments**; an existing payment or subscription always resolves **its own instance by id**, so switching the active Stripe account never re-routes old subscriptions or their webhooks. |
| `driver_type` is a MySQL `ENUM` widened with raw `ALTER TABLE` for each new driver | A plain string column; providers are registered in code |
| `cascadeOnDelete` from driver to subscriptions, so deleting a driver deletes its subscriptions and their cycles | `restrictOnDelete`; instances are soft-deleted only; a deactivated instance keeps serving existing subscriptions |
| Methods are display-only: not used in sorting, not stored with the payment, and the checkout reads mismatched keys | The method is a first-class value stored on every payment, with a fixed shape |
| `getConfigSchema()` returns Filament components, which ties the driver contract to Filament | Drivers describe their settings as plain data; the Filament plugin draws the form |
| Hard-coded type lists for availability (`ADMIN_ONLY…`, `RECURRING_…`) | Taken from the capability interfaces plus per-instance availability rules |
| Subscriptions belong to `User` only, with EUR hard-coded | Any model can be billed or pay (polymorphic); currency is set per plan and per instance |
| Only monthly periods via `anchor_day`; no `paid_until` | A period engine (§4) with a cached `paid_until` |
| Literal untranslated strings in the driver resource | Every string under the `billing::` translation namespace |

## 3. Domain model

```
Billable (contract + HasBilling trait)         e.g. Farmer, User, Student, Company
  └─ Subscription ── plan_id, billable (morph), payer (morph, defaults to billable),
       │             gateway_instance_id (null = offline), status, paid_until (cached),
       │             grace_days, auto_renew
       └─ CoveragePeriod ── starts_on, ends_on, amount, currency,
                            status: pending | paid | overdue | waived | void
Payment ── payer (morph), gateway_instance_id, method, amount, currency,
           status: recorded | confirmed | failed | refunded | void,
           reference (receipt no.), external_id, proof_path, notes,
           recorded_by, confirmed_by, confirmed_at, meta (json)
  └─ PaymentAllocation ── payment_id, coverage_period_id, amount
                          (one payment can cover many periods or many subscriptions,
                           e.g. a co-operative paying for 40 farmers at once)
Plan ── name, billable_type (optional restriction), currency, translations
  └─ PlanPrice ── duration (unit: day|week|month|quarter|year, count) or calendar_id,
                  amount, label ("1 month", "Season", "Year")
BillingCalendar ── named fixed periods (e.g. school terms, rainy season), each with dates
GatewayInstance ── name, provider (string key), credentials (encrypted:array),
                   settings, enabled_methods (json), currencies, availability rules,
                   is_active, sort, soft deletes
```

- **Amounts** are integer minor units plus an ISO currency code.
- **`paid_until`** is the latest `ends_on` of paid or waived periods. It is cached on the subscription and recomputed in one place.
- **Covered** means `paid_until + grace_days >= date`. The `HasBilling` trait provides `isCovered($date, $plan = null)` and a `scopeCovered($date)` query filter. Farmcast's weekly send would use `Farmer::active()->covered(today())`.

## 4. Period engine

`PeriodCalculator` turns a request into one or more coverage periods:

- **Duration:** a start date plus unit × count, e.g. 3 months.
- **Custom range:** explicit `starts_on`/`ends_on`, e.g. "this year".
- **Calendar:** one named period or several, e.g. "Term 2" or "Rainy season 2027".
- **Default start:** the day after the current `paid_until`, or today if the cover has lapsed. Gaps are allowed; overlaps are rejected unless an admin overrides.
- **Month-end clamping** works like Kompass: 31 Jan + 1 month = 28/29 Feb.
- **Out of scope for v1:** proration and mid-period plan changes.

## 5. Gateway layer

```php
interface PaymentProvider                        // one class per provider, registered in code
{
    public static function key(): string;        // 'offline', 'paystack', 'notchpay', ...
    public static function settingsSchema(): array;   // plain field definitions, no Filament
    public static function methods(): array;     // PaymentMethodDefinition[] it can offer
    public function forInstance(GatewayInstance $instance): static;
}
// Capability interfaces, all optional:
interface ChargesOnce          { start(PaymentIntent): Started; complete(Request|string ref): PaymentResult; }
interface ReceivesWebhooks     { verifyAndParse(Request): ?PaymentResult; }
interface Refunds              { refund(Payment, ?int amount): PaymentResult; }
interface NativeSubscriptions  { subscribe(Subscription): string; cancel(Subscription): void; }
interface ManualConfirmation   { /* marker: payments stay "recorded" until a person confirms */ }
```

- **`OfflineProvider`** is part of the core.
  - Methods: `cash`, `bank_transfer`, `mobile_money_manual` (MoMo sent to a merchant number and checked by a person), `cheque`.
  - Instance settings: the instructions shown to payers (account number, MoMo merchant number), whether a receipt number is required, and whether a proof upload is required.
- **`OmnipayProvider`** is one generic adapter. A small subclass per provider maps settings onto `Omnipay::create($name)->initialize($instance->credentials)`, using the credentials of **the instance the payment belongs to**.
- **Several instances per provider:** for example "Paystack Ghana (GHS)" and "Paystack Nigeria (NGN)". One is active per provider (`billing.gateways.one_active_per_provider`, default `true`); the others stay configured and can be switched to with one click.
- **What a user sees:** active instances × their enabled methods, filtered by currency, by billable type and by the capability needed (one-off or recurring). Each payment stores `gateway_instance_id` and `method`.

## 6. Offline flow (phase 1)

1. **Record payment.** A Filament action on any billable's resource, plus a bulk action for groups. The admin picks:
   - the payer;
   - the billable(s);
   - the plan and a price preset (1 month, season, year) or custom dates or calendar period(s);
   - the amount (suggested from the plan price and editable);
   - the instance and method;
   - the receipt or reference number, an optional proof upload, and notes.
2. **What gets created:** the `Payment`, its `CoveragePeriod`s and `PaymentAllocation`s, all in one database transaction.
3. **Confirmation** depends on a config switch. Either the payment is confirmed immediately, or it waits for maker-checker: a **different** user with the `billing.confirm` permission confirms it.
4. **When confirmed,** the periods become `paid`, `paid_until` is recomputed, and `PaymentConfirmed` plus `CoverageExtended` fire. The app listens; Farmcast, for example, can SMS a receipt.
5. **Daily command:** flags periods as overdue and fires `CoverageExpiring` (N days before the end) and `CoverageLapsed`. Farmcast can text "your forecast subscription ends on …".
6. **Voiding:** an admin can void a payment, which records a reason and re-derives coverage. Nothing is hard-deleted.

## 7. Filament plugin (optional, in the same repository)

- **`BillingPlugin::make()`** registers resources for gateway instances, plans, calendars, subscriptions and payments. Each resource can be turned on or off.
- **`RecordPaymentAction`** and a **`CoverageRelationManager`** can be dropped into any billable's resource.
- **The gateway instance form** is drawn from `settingsSchema()`. Credentials are never shown again after saving and can only be replaced.
- **Widgets:** cover expiring this week, unconfirmed payments, collected this month.
- **`GatewayMethodPicker`** is a form component for apps that let users choose how to pay.

## 8. Cross-cutting concerns

- **Translations:** a `billing::` namespace for every label, enum and notification. Ship `en`, `fr` (Cameroon) and `de` (Kompass); add `ar`/`es` when Kompass moves over.
- **Permissions:** plain Gate abilities (`billing.view`, `billing.record`, `billing.confirm`, `billing.manage-gateways`) that the app maps onto its own roles. No Spatie dependency.
- **Security:**
  - credentials are `encrypted:array` and never logged;
  - webhook signatures are always verified;
  - proof files go on a private disk and are downloaded through a permission-checked route;
  - amounts are validated on the server;
  - every change is audited (`recorded_by`, `confirmed_by`, void reason).
- **Requirements:** PHP 8.3+, Laravel 12/13, Filament 5 (plugin only). `omnipay/common` is only *suggested* until phase 2.

## 9. Delivery

- **Repository:** `<your-github>/<package-name>` (name to be decided). Apps install it with a Composer `vcs` repository entry; locally, a `path` repository makes editing easy.
- **Namespace:** to be decided, e.g. `Vendor\Billing`. The Filament plugin lives under `Vendor\Billing\Filament`.
- **Phase 1:**
  - core models and migrations, the period engine, `OfflineProvider`, record/confirm/void, the coverage scope, the daily command, events, the Filament plugin, translations (en/fr);
  - **Farmcast integration:** `Farmer` implements Billable, the weekly SMS is filtered by cover, and expiry and receipt SMS go out.
- **Phase 2:** `OmnipayProvider` plus Paystack (Ghana cards and MoMo), webhooks, and a self-service pay link.
- **Phase 3:** NotchPay and Hubtel drivers, Stripe and PayPal, then migrating Kompass (data migration: `PaymentDriver` → `GatewayInstance`, cycles → `CoveragePeriod` + `Payment`).

## 10. Critical paths and live test scenarios

| # | Path | Kind | Live test (real boundary) |
|---|---|---|---|
| 1 | Record a cash payment for 3 months → periods paid → `paid_until` = start + 3 months − 1 day | happy | SQLite + MySQL migrations run for real; model test |
| 2 | Custom range and calendar period (school term) | happy | same |
| 3 | Default start = day after `paid_until`; a lapsed cover starts today | edge | same |
| 4 | An overlapping period is rejected; an admin override is allowed | error | same |
| 5 | Maker-checker: the person who recorded it cannot confirm it; another user can | auth | Filament action test as two users |
| 6 | Voiding a payment re-derives coverage; nothing is deleted | edge | same |
| 7 | One group payment allocated to 40 billables | happy | same |
| 8 | `covered()` scope with grace days, the day before and after the edge | edge | query against a real DB |
| 9 | Two instances of one provider: activating one switches the other off; old payments keep their own instance and settings | seam | resolve by id, assert the settings used |
| 10 | Deactivating or soft-deleting an instance doesn't break existing subscriptions | edge | same |
| 11 | Credentials are encrypted at rest and not shown back in the form | security | read the raw DB row |
| 12 | Proof download is blocked without permission | security | HTTP test |
| 13 | Daily command: overdue marking and expiring/lapsed events, run twice (idempotent) | happy | run the real artisan command |
| 14 | Translations: every `billing::` key exists in each shipped locale | i18n | key-diff test |
| 15 | Farmcast: the weekly SMS skips uncovered farmers and an expiry SMS is sent | seam | Farmcast command test with the log SMS driver |
| 16 | (Phase 2) Paystack charge and webhook in sandbox | seam | opt-in live test using sandbox keys |

## 11. Decisions taken for phase 1 (defaults; change any of them)

1. **Package:** `ivanange/laravel-billing`, namespace `Ivanange\Billing`, Filament plugin under `Ivanange\Billing\Filament`. Built locally first; pushed to github.com/ivanange only once you approve.
2. **Grace period:** set per plan (`grace_days`, default 0). Farmcast only stops SMS for unpaid farmers once an admin turns on *Only send to farmers with paid cover* in Settings, so deploying doesn't cut anyone off.
3. **Maker-checker:** supported, off by default (`billing.confirmation.second_person`); with it off, a recorded payment is confirmed at once.
4. **Group payments:** in phase 1 as a bulk action (one payment, one allocation per farmer).
5. **Prices:** plans have price presets; the admin can still change the amount for each payment.

## 12. Phases 2 and 3: plan (2026-11-10)

### 12.1 Versions checked on Packagist (2026-11-10)

| Package | Version | Notes |
|---|---|---|
| `omnipay/common` | 3.5.1 (2026-02-13) | PHP ^8; finds its HTTP client through PSR-18 discovery |
| `paystackhq/omnipay-paystack` | 1.1.0 (2026-10-29) | The **official** Paystack driver (repository `PaystackHQ/omnipay-paystack`). Not archived; PHP 8 support and GitHub Actions CI were added in Oct 2026. Covers purchase (`/transaction/initialize`), completePurchase (`/transaction/verify/{ref}`) and refund. It doesn't send `channels`, so our gateway subclass adds that. |
| `omnipay/stripe` | 3.2.0 (2022-03) | Last release in 2022. Phase 3 decides whether to use it (§12.6). |
| `omnipay/paypal` | 3.0.2 (2018) | Legacy NVP/REST v1 APIs. **Skipped.** |
| `larastan/larastan` | 3.14.0 | dev only |

**Dependencies.** All of these are `suggest` + `require-dev` only, so an offline-only app installs none of them. `omnipay/common` brings `php-http/discovery`, which satisfies the `php-http/client-implementation` virtual package. At runtime we give Omnipay our own PSR-18 client, `LaravelHttpClient`, which sends through Laravel's `Http` client. So no Guzzle adapter is needed, timeouts come from config, and `Http::fake()` mocks every gateway call in tests, including the in-package NotchPay/Hubtel drivers.

### 12.2 Capability interfaces (namespace `Ivanange\Billing\Contracts`)

Providers stay stateless and are resolved from the registry. **Every call receives the `GatewayInstance` explicitly**: the payment's own instance, loaded by `payments.gateway_instance_id`. Nothing resolves credentials by provider type.

```php
interface ChargesOnce {          // hosted checkout: redirect the payer, then verify
    public function start(GatewayInstance $instance, PaymentIntent $intent): StartedCharge;
    public function verify(GatewayInstance $instance, string $reference): ChargeResult;
}
interface ReceivesWebhooks {     // throws InvalidWebhookSignature; null = event we ignore
    public function parseWebhook(GatewayInstance $instance, Request $request): ?ChargeResult;
}
interface Refunds {
    public function refund(GatewayInstance $instance, Payment $payment, ?int $amount = null): ChargeResult;
}
```

Readonly value objects in `Ivanange\Billing\Data`:

- `PaymentIntent`: reference, amount in minor units, currency, method, email, phone, description, returnUrl, metadata.
- `StartedCharge`: redirectUrl, reference, externalId.
- `ChargeResult`: status (`ChargeStatus::Succeeded|Pending|Failed`), reference, externalId, amount, currency, message.

`OmnipayProvider` (abstract) implements `ChargesOnce` and `Refunds` on top of any Omnipay gateway:

- It builds `new $gatewayClass(new PsrClient(new LaravelHttpClient))`.
- It then calls `->initialize($this->parameters($instance))`, whose values come from **that instance's** credentials and settings.
- It maps purchase/completePurchase/refund responses to `StartedCharge`/`ChargeResult`.

Subclasses only declare the gateway class, the fields, the methods and the response mapping. `PaystackProvider extends OmnipayProvider implements ReceivesWebhooks`.

### 12.3 Online payment lifecycle

New `PaymentStatus` cases:

- `pending`: started online, waiting for the provider;
- `failed`: declined, abandoned or mismatched;
- `refunded`.

```
pay link (signed URL: billable, plan, price)
  └─ GET  /billing/pay            page: active instances × methods from GatewayCatalog (offline ones show instructions)
  └─ POST /billing/pay            OnlineCheckout::start → Payment{pending, amount, currency, reference=BIL-…,
                                  meta.checkout={billable, plan, price}} → provider->start(instance, intent)
                                  → 302 to the provider's hosted page
provider ──► GET /billing/pay/return/{reference}  → provider->verify(payment's instance, reference) → OnlinePaymentSettler
provider ──► POST /billing/webhooks/{instance}    → provider->parseWebhook(instance, request) (signature with THAT
                                                    instance's secret) → payment by (instance id, reference) → OnlinePaymentSettler
OnlinePaymentSettler::settle(payment, result)     one DB transaction, payment row locked (lockForUpdate):
  - already confirmed/refunded/void → no-op (duplicate webhook, callback/webhook race)
  - succeeded: reference, amount and currency must equal the stored payment, else failed + meta.failure
    → CoverageAllocator creates the periods (start = day after paid_until at confirmation time) → confirmed
    → ledger refresh; PaymentConfirmed + CoverageExtended after commit
  - failed → failed; pending → unchanged
```

**Why cover is allocated at confirmation, not at checkout:** an abandoned checkout must not reserve dates. Otherwise the payer's retry would hit the overlap guard. `CoverageAllocator` is pulled out of `PaymentRecorder`, so offline and online payments create cover the same way.

**Late success:** a `failed` payment can still become `confirmed` when the provider later reports success for the same reference, because the money was taken. A `void` payment never can.

**`billing:reconcile-payments`** re-verifies `pending` online payments older than `billing.online.verify_after_minutes`. It marks those still unpaid after `billing.online.abandon_after_minutes` as `failed`. Idempotent.

### 12.4 Webhook and pay-link routes

**Webhooks:** `POST /billing/webhooks/{instance}`.

- Outside the `web` group, so there is no session and no CSRF; middleware is `throttle:billing-webhooks`.
- The instance is looked up by id, soft-deleted ones included (old payments keep working).
- Responses: 404 if the provider has no webhook support, 401 on a bad signature, 200 otherwise (also for unknown references, so the provider stops retrying).
- Logs never include the payload's secrets or the instance's credentials.

**Pay links:** `URL::temporarySignedRoute('billing.pay.show', …)` (`PayLink::make`).

- The POST goes to the same signed URL, inside `web` (CSRF on).
- The return route is `throttle`d. It only reads and verifies.

The Filament `PayLinkAction` generates a link and copies it to the clipboard.

### 12.5 Phase 3 drivers (inside the package)

| Driver | Endpoints | Webhook trust | Methods |
|---|---|---|---|
| **`Omnipay\NotchPay`** (`NotchPayProvider`) | `POST /payments`, `GET /payments/{ref}`, `POST /refunds` (X-Grant), all on `https://api.notchpay.co`. Auth is `Authorization: <public key>`, with no "Bearer". | `x-notch-signature` = hex HMAC-SHA256 of the raw body with the account's **hash key**. Lookup accepts our reference or NotchPay's `trx.…` (stored as `external_id`). | `mtn_momo` (`cm.mtn`), `orange_money` (`cm.orange`); XAF in whole francs; payer phone required |
| **`Omnipay\Hubtel`** (`HubtelProvider`) | `POST payproxyapi.hubtel.com/items/initiate`, `GET api-txnstatus.hubtel.com/transactions/{account}/status?clientReference=`. Basic auth with API ID:key. | Callbacks are **unsigned**. The handler takes only `ClientReference`, checks that this instance issued it, then **re-queries the status API**. The posted status and amount are never used. | `hubtel_checkout` (Hubtel's page: MoMo MTN/Telecel/AirtelTigo or card); GHS only; no refunds (Hubtel's refund API is MoMo-only and keyed by order id) |

**Doc URLs** are cited in each class.

**Hubtel's status API only answers whitelisted server IPs.** This is an operational prerequisite, documented in the README.

**Not documented by the providers, so handled defensively:**
- NotchPay's `transaction` field is sometimes an object and sometimes an id. Only `authorization_url` is relied on.
- Whether `GET /payments/{ref}` accepts the merchant's own reference is unclear. The opt-in live test checks it.

### 12.6 Stripe and PayPal: decision

**Stripe: skipped for now.**
- **Installation is fine.** `omnipay/stripe` 3.2.0 (2022) installs cleanly with omnipay/common 3.5 on PHP 8.3/8.4 and Laravel 13 (checked with `composer require --dry-run`).
- **PaymentIntents doesn't fit our redirect flow.** The `Stripe\PaymentIntents` gateway requires a payment method collected client-side (Stripe.js/Elements). It hard-codes `confirmation_method=manual` and only redirects for 3-D Secure, so it cannot serve the pay link's "redirect to a hosted page" flow.
- **Its `Stripe\Checkout` gateway is incomplete.** It never sends `client_reference_id` or metadata, `isRedirect()` is false, and it has no `completePurchase()` (upstream issues #216, #238).
- **The repository is unmaintained.** Last push 2024-06, 86 open issues.
- **Recommendation:** when Stripe is needed, write a thin in-package `Omnipay\StripeCheckout` driver, the same way as NotchPay and Hubtel: `POST /v1/checkout/sessions` with `client_reference_id`, `GET` the session to verify, and verify webhooks with the `Stripe-Signature` header (`t=…,v1=` HMAC-SHA256).

**PayPal: skipped.** `omnipay/paypal` 3.0.2 (2018) targets the legacy NVP/Express Checkout and REST v1 APIs.

### 12.7 Critical paths & live test scenarios (extends §10)

| # | Path | Kind | Test (boundary) |
|---|---|---|---|
| 17 | Pay link: a valid signature shows active instances × methods in the plan currency; an expired or tampered link is 403 | security | HTTP test against the real signed-URL middleware |
| 18 | Start a Paystack checkout: pending payment, provider called with **that instance's** secret, 302 to `authorization_url` | happy / seam | `Http::fake` asserts the request URL, Bearer key, amount and channels |
| 19 | Two Paystack instances: a payment made on A verifies with A's key after B is activated | seam | `Http::fake` asserts the Authorization header |
| 20 | Return URL verifies → confirmed, cover created from paid_until, events fired | happy | `Http::fake` verify response |
| 21 | Webhook with a valid HMAC-SHA512 → confirmed; a bad or missing signature → 401, no change | security | real HMAC computed in the test |
| 22 | Duplicate webhook, and callback + webhook for one payment → confirmed once, one set of periods, events once | idempotency | settle twice and assert counts |
| 23 | Amount or currency mismatch → failed with the reason stored, no cover | security | fake verify returns another amount |
| 24 | Declined or abandoned → failed; the reconcile command fails stale pendings, confirms late successes; void stays void | edge | real artisan command + `Http::fake` |
| 25 | Webhook for an unknown reference or another instance's payment → 200, nothing changes | edge | HTTP test |
| 26 | Offline instance on the pay page shows payer instructions and no online submit | happy | HTTP test |
| 27 | Refund (Paystack) → refunded, cover voided, ledger refreshed | happy | `Http::fake` |
| 28 | Filament: panel boots with `BillingPlugin`; every resource page and action renders | smoke | testbench + Livewire |
| 29 | Live: Paystack sandbox initialize + verify with `PAYSTACK_TEST_SECRET` | seam (live) | opt-in, skipped without the key |
| 30 | NotchPay: initialize/verify/webhook signature; live behind `NOTCHPAY_TEST_PUBLIC_KEY` | seam | `Http::fake` + opt-in live |
| 31 | Hubtel: initiate/status; callback triggers a status re-query (payload not trusted) | security / seam | `Http::fake` + opt-in live |
| 32 | Kompass dry-run mapping: drivers → instances, cycles → periods + payments (counts only, no writes) | migration | command test on fixture tables |

**Live tests:** they run only when the env key is set; CI has no keys, so they are skipped there. Every other seam (Paystack/NotchPay/Hubtel HTTP) is exercised through the real Omnipay driver code with Laravel's `Http::fake` at the socket edge, so the driver's request building and response parsing are real.
