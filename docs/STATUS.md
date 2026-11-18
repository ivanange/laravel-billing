# Project status

Last updated: 2026-11-18. Branch: `fix/phases-1-3-audit` (not merged). `main` is at v0.2.0 plus phase 3.

## Where things stand

| Phase | State |
|---|---|
| 1 Offline payments | Shipped (v0.1.0). Audited 2026-11-16; defects fixed on this branch. |
| 2 Online: Paystack, webhooks, pay links | Shipped (v0.2.0). Audited; defects fixed on this branch. |
| 3 NotchPay, Hubtel | On `main`, untagged. Audited; defects fixed on this branch. Stripe and PayPal were not built (see below). |
| 4 Gap closure (`DESIGN.md` §13) | Planned and approved in outline. Paused until this branch is merged. |

## This branch

An audit of phases 1–3 against `DESIGN.md` found real defects. They are fixed here, followed by one round of independent security and correctness review, whose findings are fixed too. `CHANGELOG.md` (Unreleased) lists every change, including the ones that can break existing apps.

Main fixes:

- Proof upload from Filament always failed; proofs are now stored server-side under a generated name, with the type checked from the file's content.
- Pay pages shared one site-wide rate limit of 10 requests a minute.
- Refunds: a timeout or unclear provider answer no longer allows a second refund. The payment stays "refund in progress" and staff settle it with the *Settle stuck refund* action. New migration `2026_11_16_000001` adds four nullable tracking columns.
- NotchPay and Hubtel verification must name the payment asked about; a response body can no longer override the HTTP status; fractional amounts are a mismatch.
- Reconcile logs every verify error and fails uncheckable stale payments as `unverifiable`, not `abandoned`.
- All services fire events after the outermost commit.
- Record-payment form: payer field (scoped by tenant, a plugin hook and `billing.record`), overlap override limited to `billing.manage`, overlap check between spans of one payment.
- Every Filament resource can be switched off.
- CI: MySQL 8.4 and PostgreSQL 17 jobs; the test bootstrap reads `DB_CONNECTION`.

### Verification

| Check | Result (2026-11-18 unless noted) |
|---|---|
| PHPUnit on SQLite | 249 tests pass, 4 skipped (live sandbox tests, no keys) |
| Pint, Larastan level 6 | Clean |
| PHPUnit on MySQL 8.4 | **Intermittent failures** on 2026-11-16: 1 to 5 tests, all proof-upload tests in `tests/Feature/Filament/RecordPaymentFormTest.php`. Not diagnosed. An earlier run of the same code passed. |
| PHPUnit on PostgreSQL 17 | **2 failures** on 2026-11-16, same file. Not diagnosed. |
| New CI jobs on GitHub | Never run before this push |
| Live sandbox tests | Not run (no keys) |

### Known open items on this branch

1. Diagnose the proof-upload test failures on MySQL and PostgreSQL. Do not merge before this.
2. The refund path and the record-payment form were each reworked after review and have not had a second review.
3. A refund the provider accepts and later fails is still recorded as refunded (planned with refund records, step 4e).
4. `refund_resolved_*` columns keep only the latest decision on a stuck refund.
5. The payer picker only offers model types registered as billables (no parent `User` paying for a student yet).

## Designed in phases 1–3 but never built

| Item | Status |
|---|---|
| Import from an app's legacy billing tables | Removed from the package: the plan and the dry-run command were specific to one app and moved there. |
| Stripe, PayPal | Skipped on purpose (`DESIGN.md` §12.6). |
| Gateway availability rules; methods filtered by billable type | Not built, not scheduled. Decision pending. |
| `overdue` / `waived` period statuses; overdue marking | Not built, not scheduled. Decision pending. |
| Plan translations, widgets, method picker, `auto_renew` | Scheduled in phase 4 (steps 4g, 4i). |
| `ar` / `es` locales | Not built. |

## Phase 4

- Plan: `DESIGN.md` §13. Steps 4a–4d (v0.3.0) are detailed; 4e–4j are outlines, to be planned when their turn comes.
- Step 4a (model swapping) was started and paused. That partial work is **not on this branch and not pushed**: it is a local git stash on the author's machine ("phase-4a paused work").
- Open decision: the table prefix (`tally_`, `dues_`, or keep `billing_`). Table names stay fixed, not configurable.

## Next steps, in order

1. Fix the MySQL/PostgreSQL test failures; confirm the CI jobs pass on GitHub.
2. Second review of the refund path and record form.
3. Decide on the never-built items above.
4. Merge, then resume phase 4 at step 4a.
