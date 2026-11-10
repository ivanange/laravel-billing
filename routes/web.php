<?php

use Illuminate\Support\Facades\Route;
use Ivanange\Billing\Http\ProofController;

Route::middleware(config('billing.routes.middleware', ['web', 'auth']))
    ->prefix(config('billing.routes.prefix', 'billing'))
    ->group(function (): void {
        Route::get('payments/{payment}/proof', ProofController::class)->name('billing.payments.proof');
    });
