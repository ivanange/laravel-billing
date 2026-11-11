# Moving Kompass billing onto `ivanange/laravel-billing`

Status: **plan, not executed** (2026-11-11). It is written from a read-only survey of the Kompass code (`/Users/ivanange/projects/MacoopA/kompass`); paths below are relative to that repository. Nothing in Kompass was changed.

The package ships a read-only check, `php artisan billing:kompass-dry-run --connection=kompass`. It reads Kompass's tables and prints what would be created: drivers → instances, cycles → periods + payments, the plans to synthesise, duplicate active drivers and overlapping cycles. It never writes.

## 1. What Kompass has today

| Kompass | Where | Notes |
|---|---|---|
| `payment_drivers` | `database/migrations/2026_01_22_000001…`, later ALTERs | `driver_type` is a MySQL ENUM: stripe, paypal, free (08_30), sepa_offline (08_31). `config` and `methods` are `encrypted:array`. Soft deletes. `is_system` exists only in the stale schema dump. |
| `subscriptions` | `…08_31_000401…` | `user_id` is the beneficiary; `payer_user_id` is nullable. Also `payment_driver_id` (**cascadeOnDelete**), `currency` (default EUR), `amount_cents`, `discount_percent`, `anchor_day`, `status` (pending_approval, active, suspended, cancelled, expired), soft deletes. |
| `subscription_billing_cycles` | `…08_31_000402…` | `period_start`/`period_end` (dates), `due_at`, `amount_cents`, `currency`, `status` (pending, paid, failed, overdue, waived), `external_ref`, `proof_path`, `confirmed_by`, `confirmed_at`. Unique (`subscription_id`, `period_start`). |
| Driver resolution | `app/Services/Payment/PaymentDriverManager.php:44-46` | Takes the first active row **of a type** and caches it per type. |
| Manual confirmation | `app/Filament/Resources/SubscriptionResource/RelationManagers/BillingCyclesRelationManager.php:135-190` | Proof upload, then `confirmed_by`, then `BillingCyclePaid`, which issues an invoice. |
| Dunning | `app/Services/Subscriptions/DunningService.php` | Reminders, then suspension after `max_unpaid_cycles`. |

## 2. Mapping

### 2.1 `PaymentDriver` → `GatewayInstance`

Kompass already keeps **one active driver per type**, through a model saving hook. That matches the package's `billing.gateways.one_active_per_provider = true`. The difference is that the package switches the active instance with an explicit `GatewayActivation`, never in a save hook. Old payments keep resolving their own instance by id, never by type.

| Kompass `driver_type` | Package provider | Method | Notes |
|---|---|---|---|
| `sepa_offline` | `offline` | `bank_transfer` | `payment_terms_days` → plan `grace_days`. `creditor_id` and `mandate_reference_prefix` → instance `settings.instructions` (text shown to payers). `max_unpaid_cycles` has no equivalent yet (see §5). |
| `free` | `offline` | `complimentary` | Amount 0, admin only, as today. |
| `stripe` | not migrated yet | — | No server-redirect Stripe provider exists yet (DESIGN §12.6). Keep the rows as soft-deleted instances with provider `stripe` only if historical payments must point at them. |
| `paypal` | not migrated | — | PayPal is skipped (legacy APIs). Historical cycles paid through PayPal are imported as payments on an archived `offline` instance named "PayPal (archived)", with `external_id` = the PayPal sale id. |

**Credentials.** Decrypt each row's `config` with Kompass's `APP_KEY`, then re-encrypt it into `credentials` (secrets) or `settings` (non-secrets). This only works if both apps share the key, or if the script runs inside Kompass. Two things in the existing config need cleaning up:

- Drop PayPal's cached `product_id` and `plans.*`. They are not credentials.
- The Stripe row saves `secret_key`, but the code reads `api_key` (`StripePaymentDriver.php:33` vs `:94-100`). Check which value is real before carrying it over.

**`driver_type` ENUM → `provider` string:** no schema change is needed. The package registers providers in code.

**Duplicate active drivers.** Nothing in the database prevents two active drivers of one type. The dry run lists them; pick one before importing.

### 2.2 Subscriptions

| Kompass | Package |
|---|---|
| `user_id` | `billable` = `App\Models\User` (morph). Tutoring could later bill `TutoringEnrollment` directly. |
| `payer_user_id` (nullable) | `payer` morph; **backfill** with the beneficiary when null, matching `Subscription::effectivePayer()`. |
| `payment_driver_id` | `gateway_instance_id` (mapped id) |
| `amount_cents`, `currency` | A synthesised **Plan** per (currency, amount): "Tutoring EUR 84.00 / month", with one `PlanPrice` (1 month). The dry run lists them. |
| `discount_percent` | Informational only: `amount_cents` is already net. Keep it in `meta`. |
| `anchor_day` | See §3. |
| `status` | active and pending_approval → `active`; cancelled and expired → `cancelled`. suspended → `active`, but cover lapses by date; the app's own suspension flag stays in Kompass. |
| `external_id` (PayPal) | `meta.kompass.external_id` |

The package's unique key is (plan, billable). Two Kompass subscriptions of one user at the same price would therefore land on the same package subscription. That is the intended behaviour: cover is per plan.

### 2.3 Billing cycles → `CoveragePeriod` + `Payment`

A Kompass cycle is a period and its payment in one row. The import splits it in two.

| Cycle status | Period status | Payment |
|---|---|---|
| `paid` | `paid` | **Created**: `confirmed`, `amount` = `amount_cents`, `reference` = `external_ref`, `proof_path` (copy the file from `billing-proofs/…` to the package's private proof disk), `confirmed_by` and `confirmed_at`, method from the driver map, `paid_on` = `confirmed_at` date. One `PaymentAllocation` per cycle. |
| `waived` | `paid` | None. Waived cycles are never set today, so there is nothing to import. |
| `pending`, `overdue`, `failed` | `pending` | None. The original status goes in `meta`. The package's `billing:check-coverage` handles reminders from then on. |

- **Dates** are copied exactly: `starts_on` = `period_start` and `ends_on` = `period_end`. `grace_ends_on` = `ends_on` + the plan's grace days.
- **Insert path.** Import with `CoverageAllocator` and an explicit `RangeTerm`, or with direct inserts in one transaction per subscription. Then run `CoverageLedger::refresh()` so `paid_until` = max paid `ends_on`. This equals Kompass's paid-cycle maximum.
- **Overlapping cycles.** A PayPal calendar month after a SEPA anchor month, for example. The dry run counts them; import them with `allowOverlap`.
- **Invoices.** `invoices.invoiceable` points at `SubscriptionBillingCycle`. Repoint it to the new `Payment` (morph type and id), or keep a `kompass_cycle_id` in `Payment.meta` and leave invoices alone. **Owner decision.**

## 3. EUR and anchor-day logic

**Currency.** EUR is hard-coded in Kompass:

- `SubscriptionService.php:53`, `PaymentService.php:61,70`, `CheckoutController.php:132`
- invoices
- PayPal refunds, which use USD

In the package, currency belongs to the plan and is checked against the gateway instance. The synthesised plans are EUR. Nothing else needs EUR. Amounts are already integer cents, which match the package's minor units, so **no conversion** is needed.

**Anchor day.** Kompass starts every period on `anchor_day`, clamped to the month length (`OfflineSepaPaymentDriver::anchorDateFor`, :298-304), and ends it the day before the next anchor. The package's monthly `DurationTerm` instead starts the day after `paid_until` and uses `addMonthsNoOverflow`. The two agree for anchors 1–28 but **drift for 29–31**:

| Anchor 31 | Kompass | Package (DurationTerm, continuing) |
|---|---|---|
| Period 1 | Jan 31 – Feb 27 | Jan 31 – Feb 27 |
| Period 2 | Feb 28 – Mar 30 | Feb 28 – Mar 27 |
| Period 3 | Mar 31 – Apr 29 | Mar 28 – Apr 27 |

Kompass defaults new anchors to `min(today, 28)` (`TutoringEnrollmentService.php:119`), so only older subscriptions are affected. Two options:

- **(a) Recommended:** add an `AnchoredMonthTerm` to the package. It is a new `Term`: the anchor is stored on the subscription, and periods are computed as Kompass computes them. That is about 40 lines plus tests.
- **(b)** Accept the drift for anchors 29–31 after migration and tell the affected users.

## 4. Extra locales (ar, es)

Kompass ships `resources/lang/{ar,de,en,es,fr}` with billing strings in `subscription*.php`, `payment*.php` and `invoice.php`. The package ships en/fr/de under `billing::billing.*`. A test enforces that every locale has every key.

Before switching Kompass over:

- Add `lang/ar/billing.php` and `lang/es/billing.php` to the package. Reuse Kompass's wording where the meaning matches: payment status, cycle status, driver names.
- Check the Filament pages in RTL for Arabic.
- Kompass strings that stay app-specific (dunning mail, invoices, the `nachhilfe.*` keys) stay in Kompass.

## 5. Gaps to close before switching

1. **Recurring charges.** SEPA's generated monthly cycles and PayPal subscriptions are recurring. The package has no `NativeSubscriptions` capability and no "generate the next pending period" job yet. Kompass's `subscriptions:generate-cycles` must either keep running against package models, or the package grows a scheduled `billing:generate-periods` for `auto_renew` subscriptions.
2. **Dunning and suspension.** `max_unpaid_cycles` → suspension. The package fires `CoverageExpiring` and `CoverageLapsed`; Kompass would listen to them and keep its own suspension rule.
3. **Invoices.** Kompass's `BillingCyclePaid` → `IssueInvoiceForPaidCycle` becomes a listener on the package's `PaymentConfirmed`.
4. **Stripe** server-redirect provider (DESIGN §12.6), if Kompass still needs card payments.
5. **Webhook verification.** Kompass's PayPal webhook passes when `webhook_id` is empty (`PayPalWebhookController.php:95-98`). The package always verifies. Make sure no traffic relies on the bypass.

## 6. Steps

1. Run `billing:kompass-dry-run` against a production copy. Resolve duplicate active drivers, the Stripe key mismatch and overlapping cycles.
2. Install the package in Kompass with `run_migrations = true`, alongside the old tables; nothing is dropped.
3. Write the import as a Kompass command (it needs Kompass's `APP_KEY` and models). It runs in one transaction per subscription and is idempotent: it keeps `meta.kompass.{driver_id, subscription_id, cycle_id}` and skips rows already imported.
4. Verify that, for every user, the package's `paid_until` equals the max `period_end` of their Kompass paid cycles. The cycle count per status must match the dry run.
5. Switch reads (`isCovered`, admin screens) to the package. Keep Kompass's tables read-only for one billing period, then archive them.
