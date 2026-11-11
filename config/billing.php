<?php

use Ivanange\Billing\Gateways\Providers\OfflineProvider;

return [

    /*
    | Payment providers the app can create gateway instances of. Each class
    | implements Ivanange\Billing\Contracts\PaymentProvider. Online
    | providers need omnipay/common (and, for Paystack,
    | paystackhq/omnipay-paystack) installed.
    */
    'providers' => [
        OfflineProvider::class,
        // Ivanange\Billing\Gateways\Providers\PaystackProvider::class,
    ],

    'gateways' => [
        /*
        | Several instances of one provider can exist (e.g. two Paystack
        | accounts); with this on, activating one deactivates the others of
        | the same provider. "Active" only decides what is offered for NEW
        | payments: existing payments always keep their own instance.
        */
        'one_active_per_provider' => true,
    ],

    'confirmation' => [
        /*
        | Maker-checker: when true, a recorded offline payment stays
        | "recorded" until a DIFFERENT user with the billing.confirm ability
        | confirms it. When false it is confirmed as soon as it is recorded.
        */
        'second_person' => false,
    ],

    'reminders' => [
        /* billing:check-coverage fires CoverageExpiring this many days before cover ends. */
        'days_before' => 3,
    ],

    'proofs' => [
        /* Keep this disk private: proofs are served through a permission-checked route. */
        'disk' => env('BILLING_PROOF_DISK', 'local'),
        'directory' => 'billing/proofs',
    ],

    'routes' => [
        'prefix' => 'billing',
        /* Admin routes (proof downloads). */
        'middleware' => ['web', 'auth'],
        /* Public pay-link pages: the links are signed, so no login is needed. */
        'pay_middleware' => ['web'],
        /* Provider webhooks: no session, no CSRF; rate-limited per IP. */
        'webhook_middleware' => ['throttle:billing-webhooks'],
    ],

    'online' => [
        /* Seconds before a call to a payment provider gives up. */
        'http_timeout' => 30,
        /* billing:reconcile-payments re-checks pending payments older than this. */
        'verify_after_minutes' => 15,
        /* ...and fails those still unpaid after this long. */
        'abandon_after_minutes' => 24 * 60,
    ],

    'pay_links' => [
        /* Default lifetime of a self-service pay link. */
        'expires_after_days' => 7,
        /* Requests per minute per IP for the pay and return pages. */
        'rate_limit' => 30,
    ],

    'webhooks' => [
        /* Requests per minute per IP. */
        'rate_limit' => 120,
    ],

    /*
    | Run the package migrations automatically. Set false and publish them
    | (tag billing-migrations) to customise.
    */
    'run_migrations' => true,

    /*
    | Default answer for the billing.* Gate abilities (view, record, confirm,
    | void, manage) when the app doesn't define them itself: true = any
    | signed-in user. Define the abilities in your AuthServiceProvider /
    | AppServiceProvider to tie them to your own roles.
    */
    'authorize_by_default' => true,

    /*
    | billing.refund sends money back through a provider, so it is denied
    | unless your app defines the ability (recommended) or you set this.
    */
    'authorize_refunds_by_default' => false,

];
