# Roadmap

What is released, what is being worked on and what comes next. The order is firm; dates are not promised.

## Released

| Release | Contents |
|---|---|
| v0.1.0 (2026-11-10) | Offline payments: billable models, plans with price presets, coverage periods (durations, date ranges, named calendars), gateway instances, record → confirm → void, group payments, the daily coverage check, the Filament plugin, English, French and German. |
| v0.2.0 (2026-11-13) | Online payments through Omnipay: Paystack, NotchPay and Hubtel, signed webhooks, pay links, reconciliation, refunds from Filament. |

## In progress: audit fixes for v0.1.0 and v0.2.0

Branch `fix/phases-1-3-audit`. An audit of the released code against `docs/DESIGN.md` found defects in proof upload, pay-page rate limiting, refunds that time out, NotchPay and Hubtel verification, and reconciliation. The fixes are written; the branch also adds MySQL 8.4 and PostgreSQL 17 jobs to CI.

Before it is merged:

- [ ] Find out why the proof-upload tests fail on MySQL and PostgreSQL (they pass on SQLite).
- [ ] Second review of the refund path and the record-payment form, which were both reworked after the first review.
- [ ] Decide the open questions listed at the end of this file.

## Next

### v0.3.0: extensibility, lifecycle, receipts

- [ ] **Extensibility.** Swappable models, route toggles, webhook events, UUID/ULID keys, named exceptions, a money formatting hook.
- [ ] **Subscription lifecycle.** Cancel, cancel now, resume and change plan, with predicates and query scopes.
- [ ] **Receipts.** Numbering, HTML and PDF, a link for the payer, a receipt notification.
- [ ] **Testing kit and release hygiene.** A public fake and model factories for host apps, an install command, upgrade and security files, CI on PHP 8.5 and on the lowest supported dependencies.

### v0.4.0: refunds, trials, admin

- [ ] **Refunds.** Partial refunds, a local refund record, refunds started from the provider's dashboard, chargebacks. This also closes a known gap: a refund the provider accepts and later fails is still recorded as refunded today.
- [ ] **Trials.** A free first period per billable and plan.
- [ ] **Admin.** Dashboard widgets (cover expiring this week, payments awaiting confirmation, collected this month), a payment method picker for host apps, translatable plan names.

### v0.5.0: quotas, invoices, renewal

- [ ] **Features and quotas.** Per-plan features, usage limits that reset on a schedule, top-ups.
- [ ] **Invoices and automatic renewal.** An invoice before payment, renewal invoices, stored payment methods (Paystack), retries after a failed charge.

### v0.6.0: tax, coupons, credit

- [ ] Flat-rate tax per plan, coupons, a credit balance per payer, proration on plan change.

## Open questions

- Gateway availability rules, and payment methods filtered by billable type: designed, not built, not scheduled.
- `overdue` and `waived` period statuses: designed, not built, not scheduled.
- Table prefix: keep `billing_` or change it before v0.3.0.
- Arabic and Spanish translations.

## Not planned

- **Stripe and PayPal drivers.** Left out on purpose (`docs/DESIGN.md` §12.6). If an app needs Stripe, the plan is a small in-package Stripe Checkout driver, built the same way as NotchPay and Hubtel.
