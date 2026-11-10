<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Ivanange\Billing\Tests\TestCase;

class ProofDownloadTest extends TestCase
{
    public function test_proof_is_only_served_to_users_allowed_to_view_billing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('billing/proofs/receipt.jpg', 'image-bytes');
        $payment = $this->record(['proofPath' => 'billing/proofs/receipt.jpg']);
        $url = route('billing.payments.proof', $payment);

        $this->getJson($url)->assertUnauthorized();

        $this->actingAs($this->user())->get($url)->assertOk()->assertDownload('receipt.jpg');

        Gate::define('billing.view', fn () => false);
        $this->actingAs($this->user('Nosy'))->get($url)->assertForbidden();
    }

    public function test_missing_proof_is_not_found(): void
    {
        Storage::fake('local');
        $payment = $this->record();

        $this->actingAs($this->user())->get(route('billing.payments.proof', $payment))->assertNotFound();
    }
}
