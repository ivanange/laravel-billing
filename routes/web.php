<?php

use Illuminate\Support\Facades\Route;
use Ivanange\Billing\Http\Controllers\PayLinkController;
use Ivanange\Billing\Http\Controllers\PaymentReturnController;
use Ivanange\Billing\Http\Controllers\WebhookController;
use Ivanange\Billing\Http\ProofController;

$prefix = config('billing.routes.prefix', 'billing');

Route::middleware(config('billing.routes.middleware', ['web', 'auth']))
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('payments/{payment}/proof', ProofController::class)->name('billing.payments.proof');
    });

Route::middleware(config('billing.routes.pay_middleware', ['web']))
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('pay', [PayLinkController::class, 'show'])
            ->middleware(['signed', 'throttle:billing-pay'])
            ->name('billing.pay.show');
        // Posted to the same signed URL, so the signature also covers the POST.
        Route::post('pay', [PayLinkController::class, 'start'])
            ->middleware(['signed', 'throttle:billing-pay'])
            ->name('billing.pay.start');
        Route::get('pay/return/{reference}', PaymentReturnController::class)
            ->middleware('throttle:billing-pay')
            ->where('reference', '[A-Za-z0-9\-]{1,64}')
            ->name('billing.pay.return');
    });

// Outside the web group: no session, no CSRF. The provider's signature is the authentication.
Route::middleware(config('billing.routes.webhook_middleware', ['throttle:billing-webhooks']))
    ->prefix($prefix)
    ->group(function (): void {
        Route::post('webhooks/{instance}', WebhookController::class)
            ->whereNumber('instance')
            ->name('billing.webhooks');
    });
