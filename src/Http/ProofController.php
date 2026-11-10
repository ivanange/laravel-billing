<?php

declare(strict_types=1);

namespace Ivanange\Billing\Http;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Ivanange\Billing\Models\Payment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof of payment files live on a private disk and are only served here,
 * behind the billing.view ability.
 */
class ProofController
{
    public function __invoke(Payment $payment): StreamedResponse
    {
        Gate::authorize('billing.view', $payment);

        $disk = Storage::disk((string) config('billing.proofs.disk', 'local'));
        abort_unless($payment->proof_path !== null && $disk->exists($payment->proof_path), 404);

        return $disk->download($payment->proof_path);
    }
}
