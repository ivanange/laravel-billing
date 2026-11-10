<?php

use Ivanange\Billing\Gateways\Providers\OfflineProvider;

return [

    /*
    | Payment providers the app can create gateway instances of. Each class
    | implements Ivanange\Billing\Contracts\PaymentProvider.
    */
    'providers' => [
        OfflineProvider::class,
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
        'middleware' => ['web', 'auth'],
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

];
