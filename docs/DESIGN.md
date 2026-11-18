# Billing package: design

Status: **approved 2026-11-10; phase 1 done (v0.1.0); phase 2 (Paystack) and phase 3 (NotchPay, Hubtel, legacy-import dry run) implemented, see §12; phase 4 planned, awaiting approval, see §13** as `ivanange/laravel-billing` (namespace `Ivanange\Billing`). Written after a survey of existing packages and a review of hand-written billing code in existing Laravel apps.

## 0. Goals

1. The package is **standalone**: its own repository, installed into apps through Composer. Host apps adapt to the package; it does not take on any one app's schema or conventions.
2. **Don't integrate payment SDKs ourselves.** Sit on an existing package that already gives one unified API over many providers. Our value is the layer on top:
   - admins create **payment driver instances**, including several instances of the same provider with different credentials and options, and choose **which one is active for each provider** (one at a time);
   - each instance turns specific **payment methods** on;
   - users choose from the active instances and methods when they subscribe or pay.
3. A **Billable** contract that any model can implement, so the same package serves different uses: farmers' SMS subscriptions, school fees by term, tutoring, quarterly memberships, and so on.
4. **Flexible periods:** a month, a quarter, a year, a school term, or any custom date range.
5. **Translations** built in.
6. It must be able to **take over from an app's own billing code**. Hand-written billing often has several driver rows per provider resolved by type, which breaks down in practice (see §2), so this design generalises that idea carefully.
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

## 2. Lessons from existing billing code (what to keep, what to fix)

Keep:
- the subscription → billing-cycle model, with explicit `period_start`/`period_end` rows;
- staff confirming manual payments, with a proof upload and `confirmed_by`;
- reminders and suspension for unpaid periods ("dunning");
- events such as `BillingCyclePaid`;
- the encrypted `config` on each driver row;
- the payer being separate from the beneficiary (`payer_user_id`).

Fix while generalising:

| Problem seen | Package rule |
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
- **Covered** means `paid_until + grace_days >= date`. The `HasBilling` trait provides `isCovered($date, $plan = null)` and a `scopeCovered($date)` query filter. An app that sends a weekly SMS would use `Farmer::active()->covered(today())`.

## 4. Period engine

`PeriodCalculator` turns a request into one or more coverage periods:

- **Duration:** a start date plus unit × count, e.g. 3 months.
- **Custom range:** explicit `starts_on`/`ends_on`, e.g. "this year".
- **Calendar:** one named period or several, e.g. "Term 2" or "Rainy season 2027".
- **Default start:** the day after the current `paid_until`, or today if the cover has lapsed. Gaps are allowed; overlaps are rejected unless an admin overrides.
- **Month-end clamping:** 31 Jan + 1 month = 28/29 Feb.
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
4. **When confirmed,** the periods become `paid`, `paid_until` is recomputed, and `PaymentConfirmed` plus `CoverageExtended` fire. The app listens and can, for example, SMS a receipt.
5. **Daily command:** flags periods as overdue and fires `CoverageExpiring` (N days before the end) and `CoverageLapsed`. The app can text "your subscription ends on …".
6. **Voiding:** an admin can void a payment, which records a reason and re-derives coverage. Nothing is hard-deleted.

## 7. Filament plugin (optional, in the same repository)

- **`BillingPlugin::make()`** registers resources for gateway instances, plans, calendars, subscriptions and payments. Each resource can be turned on or off.
- **`RecordPaymentAction`** and a **`CoverageRelationManager`** can be dropped into any billable's resource.
- **The gateway instance form** is drawn from `settingsSchema()`. Credentials are never shown again after saving and can only be replaced.
- **Widgets:** cover expiring this week, unconfirmed payments, collected this month.
- **`GatewayMethodPicker`** is a form component for apps that let users choose how to pay.

## 8. Cross-cutting concerns

- **Translations:** a `billing::` namespace for every label, enum and notification. Ship `en`, `fr` and `de`; `ar` and `es` can follow.
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
  - **first host-app integration** (done in the app, not in the package): a `Farmer` model implements Billable, a weekly SMS is filtered by cover, and expiry and receipt SMS go out.
- **Phase 2:** `OmnipayProvider` plus Paystack (Ghana cards and MoMo), webhooks, and a self-service pay link.
- **Phase 3:** NotchPay and Hubtel drivers, Stripe and PayPal, then a migration path for apps that already have billing tables (driver rows → `GatewayInstance`, billing cycles → `CoveragePeriod` + `Payment`).

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
| 15 | Host app: a weekly SMS skips uncovered farmers and an expiry SMS is sent | seam | host-app command test with the log SMS driver |
| 16 | (Phase 2) Paystack charge and webhook in sandbox | seam | opt-in live test using sandbox keys |

## 11. Decisions taken for phase 1 (defaults; change any of them)

1. **Package:** `ivanange/laravel-billing`, namespace `Ivanange\Billing`, Filament plugin under `Ivanange\Billing\Filament`. Built locally first; pushed to github.com/ivanange only once you approve.
2. **Grace period:** set per plan (`grace_days`, default 0). A host app should put its own switch in front of any cut-off (for example *Only send to farmers with paid cover*), so that installing the package doesn't cut anyone off.
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
    → ledger refresh; PaymentConfirmed + CoverageExtended after the OUTERMOST commit (DB::afterCommit:
      at once without a transaction, never if a caller's transaction rolls back; every billing event
      follows this rule, offline record/confirm/void and gateway activation included)
  - failed → failed; pending → unchanged
```

"Reference" means the provider's own answer names the payment: Paystack's `data.reference`, Hubtel's `data.clientReference`, NotchPay's `transaction.reference`/`trxref` (our reference, or the `trx.…` we looked it up by). An answer that only echoes what we asked confirms nothing. A provider amount that is not a whole number of minor units (`"5000.9"`, 15.004 GHS) is a mismatch; `"5000.00"` is 5000.

**Why cover is allocated at confirmation, not at checkout:** an abandoned checkout must not reserve dates. Otherwise the payer's retry would hit the overlap guard. `CoverageAllocator` is pulled out of `PaymentRecorder`, so offline and online payments create cover the same way.

**Late success:** a `failed` payment can still become `confirmed` when the provider later reports success for the same reference, because the money was taken. A `void` payment never can.

**`billing:reconcile-payments`** re-verifies `pending` online payments older than `billing.online.verify_after_minutes`. It marks those still pending after `billing.online.abandon_after_minutes` as `failed`: reason `abandoned` when the provider answered "not paid", `unverifiable` when the last check (the one made after the abandon window ended) errored or its answer did not name the payment (that one may really have been paid; staff should check it with the provider). Reasons are the `FailureReason` enum; the column stays a string, so a value this version does not know is shown as stored. Every verify error is logged with the payment id, the instance id and the redacted provider message; an answer about another payment is stored and logged with a fixed message, never the provider's own ("Verification successful" was about someone else). Idempotent.

**Refunds.** The payment is moved to `refunding` (and `refund_started_at` set) before the provider is called. It goes back to `confirmed` only when the refund was never sent (a `BillingException` raised before the call: refunds not supported, misconfigured instance, Omnipay's own parameter check) or the provider refused it readably: a non-2xx answer other than 408 and below 500 that carries the provider's own refusal. A 2xx is never a refusal: it is a success only when it carries the provider's success marker (Paystack `status: true`, a NotchPay `refund` object not marked failed), otherwise the outcome is unknown. A timeout, dropped connection, 408, 5xx, a transfer that broke after the answer started, or an unreadable answer (`GatewayOutcomeUnknown`) leaves it `refunding`, logs the error and tells staff to check with the provider.

`PaymentRefunder::resolveStuck(payment, refunded, by)` then completes it or puts it back, without calling the provider. It refuses until `refund_started_at` is older than `billing.online.http_timeout` plus `PaymentRefunder::RESOLVE_GRACE_SECONDS` (60 s, for the work around the call and clock differences between servers), so a refund still in flight can't be settled under it; rows from before that column existed can be settled at once. Who settled it, when and what they said is stored (`refund_resolved_by`, `refund_resolved_at`, `refund_resolution`) and `StuckRefundResolved` fires after commit. If the provider accepted a refund but recording it fails (the row changed, the database failed), the error is logged and reported with the provider's refund id, the payment is put (back) in `refunding` with `meta.refund_accepted`, and staff are told the money has gone back. `refunding` payments count in the payments navigation badge.

Known limitation: a refund the provider accepts and later fails (Paystack `refund.failed`, NotchPay refund-failure webhooks) stays recorded as refunded; handling those webhooks is planned work.

### 12.4 Webhook and pay-link routes

**Webhooks:** `POST /billing/webhooks/{instance}`.

- Outside the `web` group, so there is no session and no CSRF; middleware is `throttle:billing-webhooks`.
- The instance is looked up by id, soft-deleted ones included (old payments keep working).
- Responses: 404 if the provider has no webhook support, 401 on a bad signature, 200 otherwise (also for unknown references, so the provider stops retrying).
- Logs never include the payload's secrets or the instance's credentials.

**Pay links:** `URL::temporarySignedRoute('billing.pay.show', …)` (`PayLink::make`).

- The POST goes to the same signed URL, inside `web` (CSRF on).
- All three routes share `throttle:billing-pay`, keyed per IP. The return route is also capped per reference (it may call the provider). Starting a checkout (the POST creates a pending payment and a provider checkout) is also capped per pay link, keyed on its signature, at `billing.pay_links.checkouts_per_link_per_hour` (20) from any IP. Viewing the pay page gets only the IP limit. The return route only reads and verifies.

The Filament `PayLinkAction` generates a link and copies it to the clipboard.

### 12.5 Phase 3 drivers (inside the package)

| Driver | Endpoints | Webhook trust | Methods |
|---|---|---|---|
| **`Omnipay\NotchPay`** (`NotchPayProvider`) | `POST /payments`, `GET /payments/{ref}`, `POST /refunds` (X-Grant), all on `https://api.notchpay.co`. Auth is `Authorization: <public key>`, with no "Bearer". | `x-notch-signature` = hex HMAC-SHA256 of the raw body with the account's **hash key**. Lookup accepts our reference or NotchPay's `trx.…` (stored as `external_id`). | `mtn_momo` (`cm.mtn`), `orange_money` (`cm.orange`); any currency the instance allows, amount sent in that currency's smallest unit as NotchPay documents (whole francs for XAF); the code does not restrict the currency, so set the instance's currencies (normally XAF); payer phone required |
| **`Omnipay\Hubtel`** (`HubtelProvider`) | `POST payproxyapi.hubtel.com/items/initiate`, `GET api-txnstatus.hubtel.com/transactions/{account}/status?clientReference=`. Basic auth with API ID:key. | Callbacks are **unsigned**. The handler takes only `ClientReference`, checks that this instance issued it, then **re-queries the status API**. The posted status and amount are never used. | `hubtel_checkout` (Hubtel's page: MoMo MTN/Telecel/AirtelTigo or card); GHS only; no refunds (Hubtel's refund API is MoMo-only and keyed by order id) |

**Doc URLs** are cited in each class.

**Verify is strict.** A non-2xx answer (the real HTTP status, kept apart from any `code` field in the body), a non-JSON answer, or one without a transaction raises `GatewayError`; it is never read as "pending". Example: Hubtel returns 403 when the server IP is not whitelisted. Reconcile logs every such error. Before the abandon window ends the payment stays `pending`; after it, reconcile fails it as `unverifiable` (see §12.3) rather than keep a never-paid checkout pending forever. A later verified success (webhook, return page) still confirms it.

**NotchPay lookups** use NotchPay's own reference (`trx.…`, stored in `external_id` at checkout) when one exists. That reference is documented to work. The merchant's reference is the fallback.

**Hubtel states.** Hubtel reports only `Paid`, `Unpaid` and `Refunded`. A declined Hubtel checkout therefore stays `pending` until the reconcile command abandons it.

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
| 32 | Legacy-import dry run: drivers → instances, cycles → periods + payments (counts only, no writes) | migration | command test on fixture tables |

**Live tests:** they run only when the env key is set; CI has no keys, so they are skipped there. Every other seam (Paystack/NotchPay/Hubtel HTTP) is exercised through the real Omnipay driver code with Laravel's `Http::fake` at the socket edge, so the driver's request building and response parsing are real.

## 13. Phase 4: plan (2026-11-16, awaiting approval)

Source: a source-level comparison with `laravelcm/laravel-subscriptions` 1.8, `lucasdotvin/laravel-soulbscription`, Cashier Stripe/Paddle/Mollie and `shetabit/multipay`. Phase 4 closes **every** gap that comparison found. It is delivered as four releases so each one stays reviewable.

### 13.1 Scope: every gap and where it is closed

| Gap from the comparison | Step | Release |
|---|---|---|
| Model swapping, route toggles, webhook events, UUID/ULID keys, table names, named exceptions, money formatting hook | 4a (§13.2) | v0.3.0 |
| Subscription lifecycle (cancel, resume, change plan), predicates and scopes | 4b (§13.3) | v0.3.0 |
| Receipts: numbering, HTML/PDF, payer link, receipt notification | 4c (§13.4) | v0.3.0 |
| Public fake, factories, install command, changelog/upgrade/security files, CI (PHP 8.5, MySQL, PostgreSQL, lowest deps, nightly) | 4d (§13.5) | v0.3.0 |
| Doc and code drift | §13.7 | v0.3.0 |
| Partial refunds, local refund record, provider-initiated refunds and chargebacks | 4e (§13.11) | v0.4.0 |
| Trials | 4f (§13.12) | v0.4.0 |
| Dashboard widgets, `GatewayMethodPicker`, translatable plan names | 4g (§13.13) | v0.4.0 |
| Features and usage quotas, top-ups | 4h (§13.14) | v0.5.0 |
| Invoices (a bill before payment), automatic renewal, stored payment methods, retries | 4i (§13.15) | v0.5.0 |
| Tax, coupons, credit balance, proration | 4j (§13.16) | v0.6.0 |

Sections 13.2 to 13.10 detail v0.3.0. Sections 13.11 to 13.18 cover the later releases at design level; each gets its exact schema and assertions when its release starts, as §12 did for phases 2 and 3.

### 13.2 Extensibility (step 4a, done first because every later step touches the models)

- **Model swapping.** New config map `billing.models` (`subscription`, `payment`, `plan`, `plan_price`, `coverage_period`, `payment_allocation`, `gateway_instance`, `calendar`, `calendar_period`). A new static class `Ivanange\Billing\Billing` resolves them: `Billing::model('payment')` returns the class, `Billing::query('payment')` a builder. Every relation, service, command and Filament resource (`getModel()`) goes through it; no class in `src` names a concrete model outside `Models/`. A custom model must extend the package's model, checked when it is resolved.
- **Route toggles.** `billing.routes.register` = `['admin' => true, 'pay' => true, 'webhooks' => true]`. `routes/web.php` is split into three files, each loaded only when its switch is on. An app that turns one off registers its own routes against the same controllers.
- **Webhook events.** `WebhookReceived(GatewayInstance $instance, array $payload, bool $signed)` fires after `parseWebhook` returns without throwing, including for events we ignore. `WebhookHandled(GatewayInstance $instance, Payment $payment, ChargeResult $result)` fires after the settler ran. `$signed` comes from a new `ReceivesWebhooks::signsWebhooks(): bool`; it is `false` for Hubtel, whose payload must not be trusted. Listeners get the provider's payload, which can contain payer phone numbers and emails; the README says so.
- **UUID/ULID keys.** `billing.keys.billable` and `billing.keys.user` (`int` | `uuid` | `ulid`) are read by the create-table migration to choose `morphs`/`uuidMorphs`/`ulidMorphs` and the type of `recorded_by`, `confirmed_by`, `voided_by` and `refunded_by`. They only take effect on a fresh install; the README and `UPGRADE.md` say so. `PayLinks` and `OnlinePaymentSettler` stop assuming integer billable ids.
- **Named exceptions.** Subclasses of `BillingException`, thrown from the existing named constructors, so current `catch (BillingException)` code keeps working: `OverlappingCoverage`, `InvalidPaymentTransition`, `RefundNotSupported`, `GatewayNotOffered`, `SubscriptionNotResumable`.
- **Money.** `Money::formatUsing(?Closure)` overrides formatting. `Money::toMinor()` parses decimal strings digit by digit instead of going through `float` (today `(float) $major * 10 ** n` can round wrongly for large or many-decimal amounts).
- **Table names are fixed, not configurable** (decided 2026-11-16). Every table carries one distinctive, readable prefix so it cannot clash with the host app or another package. The prefix itself is still to be chosen (today it is `billing_`); if it changes, one migration renames the tables, as the last item of this step.

### 13.3 Subscription lifecycle (step 4b)

Cover is prepaid, so "cancel at period end" is the natural default: a cancelled subscription keeps the cover already paid for and simply stops being reminded and renewed.

New service `SubscriptionManager`. Every method runs in one transaction with the subscription row locked, and fires its event after commit.

| Method | Effect |
|---|---|
| `cancel($subscription, ?string $reason, ?Authenticatable $by)` | `status = cancelled`, sets `cancelled_at`, `cancelled_by`, `cancel_reason`. Paid cover stays. `billing:check-coverage` already skips non-active subscriptions, so reminders stop. Fires `SubscriptionCancelled`. |
| `cancelNow(...)` | As `cancel`, and cover ends today: paid periods wholly in the future become `void`; the period containing today gets `ends_on = grace_ends_on = today`, with the old end kept in a new `original_ends_on` column. Pending periods are untouched (their payment decides). Allocations are not changed, because the money was received. The ledger is refreshed. No money is returned; refunding is a separate, explicit action. |
| `resume($subscription, ?Authenticatable $by)` | Only for a cancelled subscription; otherwise `SubscriptionNotResumable`. Clears the cancellation fields, `status = active`. Cover cut short by `cancelNow` is **not** restored. Fires `SubscriptionResumed`. |
| `changePlan($subscription, Plan $to, bool $now = false, ...)` | The unique key is (plan, billable), so a plan change is a **new subscription row**, which keeps history (soulbscription's approach). The old one is cancelled (`cancelNow` when `$now`). The new row stores `switched_from_id`. While the new subscription has no periods of its own, `CoverageAllocator::defaultStart` starts its first period the day after the predecessor's `paid_until`, so cover is continuous and never doubled. Rejects a plan that does not accept the billable or has another currency. Fires `SubscriptionSwitched` with both subscriptions and, for `$now`, the unused amount of the cut period (pro rata by days), so an app can credit it. The package itself does not prorate. |

**Paying for a cancelled subscription resumes it.** `CoverageAllocator` reuses the row today regardless of status; it will now call `resume` and fire `SubscriptionResumed`, because a payment is a clear intent to continue.

**Predicates and matching scopes on `Subscription`** (each pair shares one definition, as in Cashier): `active`, `cancelled`, `covered($on)`, `onGracePeriod($on)` (after `paid_until`, on or before the grace end), `lapsed($on)` (active, past the grace end), `ended($on)` (cancelled and past the grace end), `neverPaid`, `expiringWithin($days)`. `HasBilling` gains `subscriptionTo(Plan $plan): ?Subscription`.

**Filament:** *Cancel*, *Cancel now*, *Resume* and *Change plan* actions on `SubscriptionResource`, gated by a new ability `billing.manage-subscriptions` (default from `billing.authorize_by_default`). Status filters use the new scopes. Translations in `en`, `fr`, `de`.

### 13.4 Receipts (step 4c)

- **One receipt per confirmed payment.** New columns on `billing_payments`: `receipt_number` (nullable, unique) and `receipt_issued_at`. No separate table: a receipt is a view of the payment, its payer, its allocations and their periods.
- **Numbering.** New table `billing_sequences` (`key` unique, `next`). `ReceiptNumberer::next()` locks the row with `lockForUpdate`, so numbers are gapless and safe under concurrency. (Cashier Mollie's `Order::count() + 1` with no unique index is the pattern to avoid.) Format from `billing.receipts.number_format`, default `RCT-{year}-{seq:6}`; the sequence key includes the year when the format does, so it restarts each year.
- **When it is issued.** Inside the confirming transaction, in both `PaymentConfirmer` and `OnlinePaymentSettler`. A voided or refunded payment keeps its number; the document then shows the status. For payments confirmed before the upgrade, `billing:issue-receipts` assigns numbers in `confirmed_at` order. It is a command, not part of the migration, so a large table does not block a deploy.
- **Rendering.** Contract `ReceiptRenderer::render(Payment $payment): string` (PDF bytes), bound from `billing.receipts.renderer`. `DompdfReceiptRenderer` is the default when `dompdf/dompdf` is installed (`suggest` + `require-dev`, chroot limited to the package views, remote files off). Without it the receipt is HTML only and the PDF route returns 404. The view `billing::receipts.show` is publishable and translated. Seller name, address, tax number and logo come from `billing.receipts.seller`.
- **Routes** (in the `admin` and `pay` groups of §13.2):
  - `GET billing/payments/{payment}/receipt` and `.../receipt.pdf`, behind `billing.view`;
  - `GET billing/receipts/{payment}`, a temporary signed link for the payer, made with `ReceiptLinks::make($payment)` and shown on the pay-link result page.
- **Filament:** *Receipt* action on the payment view page (open, download, copy payer link).
- **Notification (opt-in).** `PaymentReceiptNotification` (mail, queued, with the signed link), sent on `PaymentConfirmed` when `billing.receipts.notify_payer` is true and the payer is `Notifiable`. Off by default; SMS stays with the app, through the event.

### 13.5 Testing kit and release hygiene (step 4d, independent of 4b and 4c)

- **`Ivanange\Billing\Testing\FakeProvider`** (key `fake`): implements `ChargesOnce`, `ReceivesWebhooks` and `Refunds` with no HTTP. Outcomes are scripted: `Billing::fake()->succeed()`, `->decline()`, `->stayPending()`, `->failRefund()`. `Billing::fake()` registers the provider, creates an active instance, and returns an object with `assertCharged($amount, $currency)`, `assertRefunded($payment)` and `webhook($payment)` (a signed request for the instance). It throws when the app environment is `production`.
- **Model factories** for every model, in `database/factories`, wired with `newFactory()` so apps can use `Payment::factory()->confirmed()`.
- **`billing:install`**: publishes the config, offers to publish migrations, prints the schedule lines to add.
- **Files:** `CHANGELOG.md` (back-filled for v0.1.0 and v0.2.0, Keep a Changelog format), `UPGRADE.md` (0.2 → 0.3: new migration, `billing:issue-receipts`, new ability), `SECURITY.md` (private reporting address, supported versions).
- **CI:** add PHP 8.5; a MySQL 8 job and a PostgreSQL job running the full suite (§10 asked for real MySQL and it never ran in CI); one `--prefer-lowest` job; a nightly schedule.

### 13.6 Schema changes (one new migration, all columns nullable, no data rewrite)

| Table | Change |
|---|---|
| `billing_subscriptions` | `cancelled_at`, `cancelled_by`, `cancel_reason`, `switched_from_id` (FK to itself, `nullOnDelete`) |
| `billing_coverage_periods` | `original_ends_on` |
| `billing_payments` | `receipt_number` (unique), `receipt_issued_at` |
| `billing_sequences` (new) | `key` (unique), `next` |

### 13.7 Corrections to earlier sections

These describe what the code does today; phase 4 does not change them.

- §3: `CoveragePeriod.status` is `pending | paid | void`. `overdue` and `waived` were never built.
- §3: `Subscription` has no `grace_days` (it is on the plan) and no `auto_renew`.
- §5: `NativeSubscriptions` does not exist and is replaced by `ChargesStoredMethod` in §13.15.
- §7: the widgets and `GatewayMethodPicker` were not built; they are step 4g (§13.13).

### 13.8 Order and size

| Step | Depends on | Rough size |
|---|---|---|
| 4a Extensibility | none | touches most files, little new logic |
| 4b Lifecycle | 4a | one service, one migration part, 4 Filament actions |
| 4c Receipts | 4a | numberer, renderer, 3 routes, view, action, notification |
| 4d Testing kit and hygiene | 4a for the fake and factories; files and CI need nothing | can run alongside 4b and 4c |

One pull request per step. 4b and 4c both add to the same new migration file, so they are done one after the other, not in parallel.

### 13.9 Critical paths & live test scenarios (extends §10 and §12.7)

| # | Path | Kind | Test (boundary) |
|---|---|---|---|
| 33 | A custom `Payment` and `Subscription` model set in config is the class every relation, service and Filament resource returns | seam | real DB; record + confirm, assert classes |
| 34 | A model that does not extend the package's is rejected at resolution | error | unit |
| 35 | With `routes.register.webhooks` off the webhook URL is 404 while pay links still work | edge | HTTP test |
| 36 | `WebhookReceived` fires for a signed event we ignore; never for a bad signature; `signed` is false for Hubtel | security | HTTP test with a real HMAC |
| 37 | UUID billable: record, confirm, pay link, webhook settle | seam | fresh migration with `keys.billable = uuid`, real DB |
| 38 | `cancel`: cover stays until `paid_until`; no expiring/lapsed events afterwards | happy | real artisan command |
| 39 | `cancelNow`: future periods void, current one cut to today with `original_ends_on` kept, `covered()` false tomorrow, allocations unchanged | edge | real DB |
| 40 | `resume` on an active subscription throws; on a cancelled one restores `active`; cut cover is not restored | error | model test |
| 41 | A payment for a cancelled subscription resumes it and fires `SubscriptionResumed` once | edge | recorder + online settler |
| 42 | `changePlan` at period end: new row, `switched_from_id`, first period starts the day after the old `paid_until` | happy | real DB |
| 43 | `changePlan` now: old cover cut, event carries the unused amount; another currency is rejected | edge | model test |
| 44 | Two concurrent lifecycle calls on one subscription end in one consistent state | concurrency | two connections on MySQL in CI |
| 45 | Each predicate and its scope agree on the day before, on, and after `paid_until` and the grace end | edge | data-provider test against a real DB |
| 46 | Receipt number assigned once on confirm, offline and online; a duplicate webhook does not assign a second | idempotency | settle twice |
| 47 | 20 confirmations in parallel give 20 distinct, gapless numbers | concurrency | MySQL and PostgreSQL jobs in CI |
| 48 | A new year restarts the sequence when the format has `{year}` | edge | frozen clock |
| 49 | `billing:issue-receipts` numbers old confirmed payments in order and is idempotent | migration | real artisan command, run twice |
| 50 | Receipt HTML shows payer, beneficiaries, periods, method and amount in `en`, `fr`, `de`; a refunded payment shows its status | i18n | HTTP test |
| 51 | PDF route returns a real PDF with dompdf installed and 404 without a renderer | seam | real dompdf, assert `%PDF` header |
| 52 | Payer receipt link: valid signature 200; tampered or expired 403; admin route 403 without `billing.view` | security | real signed-URL middleware |
| 53 | Receipt notification is sent only when the option is on and the payer is notifiable | edge | `Notification::fake` |
| 54 | `Billing::fake()`: full checkout → webhook → confirmed → refund with no HTTP; throws in production | seam | the package's own online tests re-run on the fake |
| 55 | Every factory creates a valid row; states (`confirmed`, `cancelled`) pass the same rules as the services | happy | real DB |
| 56 | Whole suite on MySQL 8 and PostgreSQL | seam (live) | CI jobs |
| 57 | Upgrade: v0.2.0 schema with data → new migration → existing payments and cover unchanged | migration | migrate a v0.2.0 fixture DB |

### 13.10 Decisions taken as defaults (change any of them)

1. **Models are swapped through config**, not Cashier-style static setters, so the choice survives `config:cache` and is visible in one file.
2. **`cancelNow` never refunds.** Cutting cover and returning money stay two separate actions with two abilities.
3. **Paying for a cancelled subscription resumes it** instead of being rejected.
4. **Plan changes do not prorate in v0.3.0.** The unused amount is reported in the event; step 4j turns it into credit.
5. **Receipts are numbered per year** and live on the payment row. Invoices (a bill issued before payment) arrive in step 4i with their own number sequence.
6. **dompdf is optional.** Without it receipts are HTML only.
7. **The receipt e-mail is off by default.**
8. **Table names: one fixed prefix**, not configurable.
9. **Partial refunds give back cover only when the admin picks the periods** (§13.11); an amount-only refund leaves cover alone.
10. **A trial is a zero-amount coverage period**, so `covered()` and the reminders need no special case (§13.12).
11. **Recurrence is driven by us, not by provider-side subscriptions** (§13.15), because mobile money has none and each provider's differ. `NativeSubscriptions` from §5 is dropped in favour of `ChargesStoredMethod`.
12. **Tax is a flat rate per plan with an optional per-billable override**; no VAT-ID validation or tax-by-location service (§13.16).

### 13.11 Step 4e: refunds (v0.4.0)

- **Local record.** New table `billing_refunds`: payment, amount, status (`pending | succeeded | failed`), source (`admin | provider`), external id (unique per gateway instance), reason, who, timestamps. `billing_payments` gains `amount_refunded`. A payment is `refunded` when `amount_refunded = amount`; before that it stays `confirmed` and `isPartiallyRefunded()` is true.
- **Which days go back.** This is the rule `PaymentRefunder` says is missing today. Two modes:
  - *By period:* the admin ticks coverage periods of the payment; the amount is the sum of their allocations and those periods become `void`.
  - *Amount only:* any amount up to what is left; cover is unchanged (a goodwill refund).
  A full refund keeps today's behaviour (all cover void).
- **Same safety as now.** The refund row is created `pending` under the payment's row lock before the provider is called, so two clicks cannot send two refunds and the total can never exceed the payment.
- **Provider-initiated refunds and chargebacks.** `parseWebhook` may return a `RefundResult` or a `DisputeResult` besides `ChargeResult`. A refund made in the provider's dashboard creates or completes a `source = provider` row, idempotent on the external id. A chargeback fires `PaymentDisputed` and flags the payment; cover is not touched automatically. Paystack and NotchPay event names and payloads are checked against their current docs when this step starts; Hubtel has neither.
- **Events:** `RefundInitiated`, `RefundSucceeded`, `RefundFailed`, `PaymentDisputed`. `PaymentRefunded` still fires on a full refund.

### 13.12 Step 4f: trials (v0.4.0)

- `billing_plans.trial_days` (default 0) and `billing_coverage_periods.kind` (`paid | trial`, default `paid`).
- `SubscriptionManager::startTrial($billable, $plan, ?int $days = null)` creates the subscription and one `paid`-status period of kind `trial`, amount 0, with no payment. One trial per billable and plan, ever.
- Because the trial is ordinary cover, `covered()` is true during it, `CoverageExpiring` fires before it ends, and the first payment's default start is the day after it.
- `onTrial()` predicate and scope; `TrialStarted` event; a *Start trial* Filament action. Revenue figures exclude `trial` periods.

### 13.13 Step 4g: admin and translations (v0.4.0)

- **Widgets** promised in §7: cover expiring this week, payments awaiting confirmation, collected this month by currency.
- **`GatewayMethodPicker`**, the form component for apps that let users choose how to pay, fed by `GatewayCatalog`.
- **Translatable plan names.** `name` and `description` on plans and `label` on plan prices and calendar periods become JSON keyed by locale, through a small in-package cast (no new dependency). Reads fall back to the app's fallback locale, then to the first value. The migration wraps each existing string under the app's default locale. Filament forms get one tab per locale from `billing.locales`.

### 13.14 Step 4h: features and quotas (v0.5.0)

- **Tables:** `billing_features` (key unique, name, type `flag | quota`, reset unit and count); `billing_plan_features` (plan, feature, limit, null = unlimited); `billing_feature_usage` (subscription, feature, window start and end, used; unique per subscription, feature and window); `billing_feature_grants` (top-ups: billable, feature, amount, remaining, expiry).
- **API on `HasBilling`:** `hasFeature($key)`, `canUse($key, $n = 1)`, `consume($key, $n = 1)`, `remaining($key)`, `grant($key, $amount, $expiresOn)`. An entitlement requires cover on that day.
- **Windows** are anchored to the start of the current coverage period and advance by the feature's reset interval.
- **Atomic.** `consume` is one conditional update (`used + n <= limit`) inside a transaction, with grants spent oldest-expiry first. Both laravelcm and soulbscription read, check and write without a lock, so concurrent calls can overdraw; this is the mistake to avoid.
- **Events:** `FeatureConsumed`, `QuotaExhausted`. Filament: features on the plan form, usage on the subscription page.

### 13.15 Step 4i: invoices and automatic renewal (v0.5.0)

- **Invoice = a bill before payment.** `billing_invoices`: number (own sequence in `billing_sequences`), billable, payer, subscription, plan price, term, lines, amount, currency, due date, status (`open | paid | void | uncollectible`). A pay link can point at an invoice; the payment that settles it creates the cover exactly as today, and the receipt refers to the invoice.
- **Renewal.** `billing_subscriptions.auto_renew`. `billing:renew` (daily) issues an invoice for the next term, at the last price preset used, `billing.renewal.days_before` days before `paid_until`, and fires `InvoiceIssued` so the app can send the pay link by SMS or e-mail. Running it twice issues one invoice.
- **Stored payment methods.** New capability `ChargesStoredMethod` (`charge(GatewayInstance, StoredMethod, PaymentIntent): ChargeResult`) and table `billing_payment_methods` (payer, gateway instance, encrypted token, brand, last four, expiry). Paystack implements it with the reusable authorization its verify response returns, stored only with the payer's consent on the pay page. NotchPay, Hubtel and offline have no such thing, so their renewals are invoice plus reminder.
- **Retries.** A failed automatic charge is retried on `billing.renewal.retry_days` (default 1, 3, 7 days after the due date), firing `RenewalFailed` each time, then the invoice becomes `uncollectible` and `InvoiceUncollectible` fires. Cover simply lapses through the existing grace rules; nothing is cancelled automatically.

### 13.16 Step 4j: tax, coupons, credit, proration (v0.6.0)

Builds on invoices, which give every adjustment a line to live on.

- **Tax.** `billing_plans.tax_rate` (basis points) and `prices_include_tax`; a billable may override the rate through an optional `billingTaxRate()` method. Invoices and receipts show net, tax and gross per line; amounts are stored, not recomputed.
- **Coupons.** `billing_coupons` (code, percent or fixed amount with currency, redemption limits, expiry, optional plan restriction) and `billing_coupon_redemptions`. Applied on the pay page, the record-payment form and renewal invoices; a redemption is counted under a row lock.
- **Credit balance.** `billing_credit_entries`: a signed ledger per payer and currency (source: overpayment, plan change, manual adjustment, refund kept as credit). The balance is the sum, and it is applied to the next invoice before any charge.
- **Proration.** `changePlan(now: true)` credits the unused amount it already computes in step 4b.

### 13.17 Order of the later steps

| Step | Depends on | Notes |
|---|---|---|
| 4e Refunds | v0.3.0 | independent of 4f and 4g |
| 4f Trials | 4b | small |
| 4g Admin and translations | 4a | independent; the JSON migration needs an upgrade note |
| 4h Features and quotas | 4b | independent of 4i |
| 4i Invoices and renewal | 4c (sequences), 4e (refund of an invoiced payment) | the largest step |
| 4j Tax, coupons, credit | 4i | |

### 13.18 Critical paths for the later steps (refined into exact assertions when each starts)

| # | Path | Kind | Test (boundary) |
|---|---|---|---|
| 58 | Partial refund by period: chosen periods void, others stay, `amount_refunded` right, provider called with that amount | happy / seam | `Http::fake` asserts the request |
| 59 | Amount-only refund leaves cover unchanged; the sum of refunds can never exceed the payment, also with two requests at once | concurrency | MySQL job |
| 60 | Provider refuses or times out: refund row `failed`, payment unchanged | error | `Http::fake` |
| 61 | Refund webhook from the provider's dashboard creates one refund row; a duplicate creates none | idempotency | HTTP test with a real signature |
| 62 | Chargeback webhook fires `PaymentDisputed` once and leaves cover alone | edge | HTTP test |
| 63 | Trial: covered during it, not after; second trial for the same plan rejected; first payment starts the day after | happy / error | real DB |
| 64 | Trial periods are excluded from collected-amount figures | edge | widget query |
| 65 | Plan name migration keeps every existing name; reads fall back when a locale is missing | migration / i18n | migrate a v0.3.0 fixture DB |
| 66 | Widgets and `GatewayMethodPicker` render with data in a real panel | smoke | testbench + Livewire |
| 67 | `consume` up to the limit succeeds, one more fails; 50 parallel calls never overdraw | concurrency | MySQL and PostgreSQL jobs |
| 68 | Usage window resets at the interval; grants are spent oldest first and expire | edge | frozen clock |
| 69 | No cover means no entitlement, whatever the usage | edge | real DB |
| 70 | `billing:renew` issues one invoice per due subscription, run twice | idempotency | real artisan command |
| 71 | Paying an invoice by pay link confirms it and creates cover once | happy | `Http::fake` |
| 72 | Paystack stored-method charge with that instance's key; the token is encrypted at rest and never shown | seam / security | `Http::fake` + raw DB row; opt-in sandbox test |
| 73 | Failed automatic charge retries on the configured days, then `uncollectible`; cover lapses by the grace rules | edge | frozen clock + real command |
| 74 | Tax-inclusive and tax-exclusive plans give net + tax = gross on invoice and receipt, with no rounding drift across split periods | edge | unit, data provider |
| 75 | Coupon: percent and fixed, expired, wrong plan, over its redemption limit under two requests at once | error / concurrency | MySQL job |
| 76 | Credit from a plan change is applied to the next invoice and the ledger sums to the balance | happy | real DB |
| 77 | Upgrade path v0.3 → v0.4 → v0.5 → v0.6 on a database with data | migration | fixture DB per release |
