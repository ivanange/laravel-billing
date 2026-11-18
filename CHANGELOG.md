# Changelog

All notable changes to `ivanange/laravel-billing` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is 0.x, a minor release may break existing apps; such changes are listed under "Breaking and behaviour changes".

## [Unreleased]

> **Also in this release, not yet sorted into the sections below:**
>
> - Migration `2026_11_16_000001`: nullable `refund_started_at`, `refund_resolved_at`, `refund_resolved_by`, `refund_resolution` on payments.
> - `PaymentRefunder::resolveStuck()` refuses while the refund call may still be running (HTTP timeout + 60 s); it records who decided and fires `StuckRefundResolved`. `PaymentRefunder` and `OnlineCheckout` constructors gained a parameter.
> - A refund the provider accepted but that could not be recorded stays "refund in progress" with `meta.refund_accepted` and throws `refund_sent_not_recorded`. A 2xx refund answer is never treated as a refusal.
> - `FailureReason` enum. **Breaking for published language files:** `billing.failure.*` moved to `billing.enums.failure_reason.*`; `ui.payment.awaiting_badge` became `attention_badge`. `OnlinePaymentSettler::abandon()` takes the enum.
> - Every service (record, confirm, void, checkout start, gateway activation) now fires its events after the outermost commit, never on rollback.
> - One pay link can start at most `pay_links.checkouts_per_link_per_hour` checkouts (default 20).
> - The payments navigation badge also counts refunds in progress.
> - `billing.record` is now asked for each billable and for the payer when recording.

### Breaking and behaviour changes

- **Removed the app-specific migration dry run.** `billing:kompass-dry-run`, `Migration\KompassInspector` and `docs/KOMPASS_MIGRATION.md` are gone. They read one app's legacy tables; that tool now lives in that app. The package ships no import.
- **Events wait for the outermost commit.** Billing events fired inside a caller's database transaction are dispatched only after it commits, and never if it rolls back. With no transaction open they still fire at once.
- **Reconciliation: `unverifiable` instead of `abandoned`.** A pending payment whose status check kept failing (outage, IP not whitelisted, an answer that does not name the payment) now ends `failed` with reason `unverifiable`, with the provider's message in its metadata. It may have been paid: the return page re-checks such payments for a day, like `declined` and `abandoned` ones, and a verified success (webhook or return page) still confirms them. The payments list filters by failure reason. Payments that were simply never paid still end `abandoned`.
- **`OnlinePaymentSettler::abandon()`** takes an optional reason and details: `abandon(Payment $payment, FailureReason $reason = FailureReason::Abandoned, array $details = [])`. Subclasses overriding it must update their signature.
- **Refunds with an unknown outcome stay `refunding`.** `PaymentRefunder::refund()` puts a payment back to `confirmed` only on a definite refusal from the provider. A timeout, dropped connection, 5xx, 408 or unreadable answer leaves it `refunding` and throws a `BillingException` telling staff to check with the provider; so does a refund the provider accepted but that could not be recorded (logged as an error). Providers signal the unknown case with the new `GatewayOutcomeUnknown` (a `GatewayError`). Settle such payments with `PaymentRefunder::resolveStuck($payment, $refunded, $by)` or the new **Settle stuck refund** Filament action (needs `billing.refund`).
- **Provider response data.** The decoded refund and verify responses of the NotchPay and Hubtel Omnipay drivers carry the HTTP status as `httpStatus` (NotchPay used `code`, which the body also uses). The real status always overrides a `httpStatus` key in the body. NotchPay treats any 2xx as success (was 200/201/202). Paystack refunds keep their HTTP status (new `RefundRequest`), and only a 2xx `{"status": true}` or a 4xx `{"status": false}` counts as an answer.
- **Verify results carry the provider's reference.** `verify()` returns the reference the provider's answer names (Hubtel's `clientReference`; for NotchPay ours only when the answer names it) instead of echoing the one asked about, and an empty reference when the answer is about another payment. Such an answer confirms nothing.
- **Stricter amount parsing.** Provider amounts with more decimals than the currency allows, or non-whole minor amounts, are read as unknown (`OmnipayProvider::intOrNull()`, `minorOrNull()`), so the payment fails as a mismatch instead of matching after rounding.
- **One payment cannot cover the same days twice.** Two spans of one payment that share days (e.g. overlapping calendar periods) are refused unless `allowOverlap` is set, with their own message (`errors.overlap_in_payment`).
- **Subscription payer.** A new subscription created by a group payment without a payer is now paid by its own billable (was no payer).
- **Record payment form.**
  - It has "Paid by" fields: an optional payer among the `billables()` types, scoped to the current Filament tenant and to `payerQueryUsing()`, and checked with `billing.record` (see the README). In a tenant panel, a payer type with neither a resource on the panel nor the panel's ownership relationship offers nobody until `payerQueryUsing()` is set.
  - `billing.record` now receives each billable being recorded for (and the payer), as it already did for pay links; the row action's visibility passes its record. A gate that type-hints one model class must accept every billable and payer type.
  - The "allow overlap" toggle is shown to, and honoured for, `billing.manage` only.
  - The proof upload is stored server-side as `<proofs directory>/<ULID>.<ext>` while the payment is recorded; a path submitted by the browser is never accepted. Only JPEG, PNG, WebP and PDF up to 5 MB are accepted, judged by the file's content.
  - `RecordPaymentForm::draft()` accepts a proof path only if it is a ULID-named file of an allowed type directly in the proofs directory, exists, and no other payment uses it; it throws `InvalidArgumentException` otherwise.
  - `RecordPaymentForm::handle()` records inside its own transaction. If a listener fails after the payment is saved, the payment and its proof are kept, the error is reported, and staff are told it was recorded (no retry is invited).
- **`ConfirmPaymentAction`** is hidden from the person who recorded the payment when maker-checker (`confirmation.second_person`) is on, since the confirmation would be refused.
- **Payment link** in `CoveragePeriodsRelationManager` is hidden when the payments resource is switched off.

### Added

- `BillingPlugin` methods: `payments()`, `subscriptions()`, `plans()`, `gatewayInstances()` (switch a resource off, like `calendars()`), `billableSearchColumns()`, `payerQueryUsing()`, `current()` and `currentPanel()`.
- "Paid by" payer picker on the record payment actions (`PayerFields`, `PayerQuery`), with case-insensitive search by the configured columns or the key.
- `PaymentRefunder::resolveStuck()`, `PaymentRefunder::resolvableAt()`, `ResolveStuckRefundAction`, `GatewayOutcomeUnknown`.
- Payments list: failure reason column and filter; the navigation badge also counts refunds in progress.
- CI runs the suite on MySQL 8.4 and PostgreSQL 17; the test bootstrap reads `DB_CONNECTION` (and `DB_*`) to pick the database, SQLite in memory by default.
- `CHANGELOG.md`.

### Fixed

- The pay page's per-reference rate limit no longer puts every payer of the site in one bucket (the pay page has no reference).
- Payer search on PostgreSQL: no error for an all-digit term on a uuid key or a number beyond the key's range, case-insensitive matching, and `%`, `_` taken literally.
- A tampered payer id is a form error on every database engine, not a server error on PostgreSQL.
- `BillingPlugin::current()` returns null instead of throwing when no Filament panel is current or default (console commands, jobs, Livewire components outside a panel).
- A listener failing after a recorded payment was committed no longer deletes the payment's proof file, nor shows "not recorded" and invites a duplicate.
- An HTML or SVG file can no longer be stored as a proof under an image name.
- Overlapping spans within one payment are reported as such, not as existing cover.
- Status checks that keep failing no longer end as `abandoned`, hiding payments that may have been paid.

### Changed

- Clearer "Paid by" wording in English and German.

## [0.2.0] - 2026-11-13

### Added

- Online payments through Omnipay: `OmnipayProvider` base class and the capability contracts `ChargesOnce`, `ReceivesWebhooks` and `Refunds`.
- Paystack provider (cards and Ghana mobile money), NotchPay provider (Cameroon: MTN MoMo and Orange Money, XAF) and Hubtel provider (Ghana online checkout), each with its Omnipay driver; `omnipay/common` and, for Paystack, `paystackhq/omnipay-paystack` are suggested dependencies.
- Online payment lifecycle: `pending` → `confirmed` / `failed` → `refunded`, confirmed only when the provider confirms the same reference, amount and currency; `PaymentFailed` and `PaymentRefunded` events.
- Webhooks at `/billing/webhooks/{gateway instance}` (verified per provider, rate-limited) and a return page that re-checks the payment with the provider.
- Signed, expiring self-service pay links (`PayLinks`, `PayLinkAction`) with public pay pages.
- `billing:reconcile-payments` command to re-check pending payments and abandon stale checkouts.
- Refunds (`PaymentRefunder`, `RefundPaymentAction`) behind the `billing.refund` ability, denied unless the app defines it (`authorize_refunds_by_default`).
- `billing:kompass-dry-run` command and `docs/KOMPASS_MIGRATION.md`.
- Configuration: `online.*`, `pay_links.*`, `webhooks.*`, route middleware for pay pages and webhooks.
- Migrations adding online columns to payments and a unique provider id per gateway.
- Larastan (level 6) in CI, opt-in live sandbox tests (`tests/Live`), Filament panel smoke tests.

### Fixed

- Void and refund transitions are guarded against invalid states; Paystack payments require Paystack's own reference; NotchPay successes are confirmed from the status API.

## [0.1.0] - 2026-11-10

### Added

- Gateway instances: several per provider, one active per provider by default; payments keep the instance they were made on.
- `Billable` contract and `HasBilling` trait: `covered()` scope with grace days, `isCovered()`, `paidUntil()`.
- Plans and prices with duration, custom range and calendar (school term style) terms.
- Offline payments: record → confirm → void, optional maker-checker (`confirmation.second_person`), group payments covering several billables, required reference or proof per gateway, complimentary cover.
- Daily `billing:check-coverage` command firing `CoverageExpiring` and `CoverageLapsed`.
- Proof-of-payment downloads behind `billing.view`.
- Filament 5 plugin: gateway instance, plan, calendar, subscription and payment resources; record, group record, confirm and void actions; coverage periods relation manager.
- Gate abilities `billing.view`, `billing.record`, `billing.confirm`, `billing.void`, `billing.manage`.
- English, French and German translations.

[Unreleased]: https://github.com/ivanange/laravel-billing/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/ivanange/laravel-billing/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ivanange/laravel-billing/releases/tag/v0.1.0
