<?php

declare(strict_types=1);

namespace Ivanange\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Ivanange\Billing\Contracts\ReceivesWebhooks;
use Ivanange\Billing\Exceptions\InvalidWebhook;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlinePaymentSettler;

/**
 * POST /billing/webhooks/{instance}: one URL per gateway instance, so the
 * signature is checked with THAT instance's secret. Soft-deleted
 * instances still accept webhooks for their older payments.
 *
 * Responses: 404 unknown instance or no webhook support, 401 bad
 * signature, 200 otherwise (also for references we don't know, so the
 * provider stops retrying). Payloads and secrets are never logged.
 */
class WebhookController
{
    public function __invoke(Request $request, int $instance, OnlinePaymentSettler $settler): Response
    {
        $gateway = GatewayInstance::query()->withTrashed()->find($instance);
        abort_if($gateway === null, 404);

        try {
            $provider = $gateway->gateway();
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless($provider instanceof ReceivesWebhooks, 404);

        try {
            $result = $provider->parseWebhook($gateway, $request);
        } catch (InvalidWebhook $e) {
            Log::warning('billing: webhook rejected', ['gateway_instance_id' => $gateway->id, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response('', 401);
        }

        if ($result === null) {
            return response('', 200);
        }

        $payment = Payment::query()
            ->where('gateway_instance_id', $gateway->id)
            ->where(fn ($query) => $query->where('reference', $result->reference)->orWhere('external_id', $result->reference))
            ->first();

        if ($payment === null) {
            Log::info('billing: webhook for an unknown reference', ['gateway_instance_id' => $gateway->id]);

            return response('', 200);
        }

        $settler->settle($payment, $result);

        return response('', 200);
    }
}
