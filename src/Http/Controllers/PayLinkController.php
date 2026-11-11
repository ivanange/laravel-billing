<?php

declare(strict_types=1);

namespace Ivanange\Billing\Http\Controllers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Contracts\ChargesOnce;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Gateways\GatewayCatalog;
use Ivanange\Billing\Gateways\Omnipay\OmnipayProvider;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Services\OnlineCheckout;
use Ivanange\Billing\Services\PayLinks;

/**
 * The public pay page behind a signed pay link: pick an active gateway and
 * method, then go to the provider. Offline gateways show their payer
 * instructions instead.
 */
class PayLinkController
{
    /** The pay pages carry a bearer link and personal data: never cache them or leak them in Referer. */
    public const PRIVATE_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex',
    ];

    public function __construct(
        private readonly PayLinks $links,
        private readonly GatewayCatalog $catalog,
        private readonly ViewFactory $views,
    ) {}

    public function show(Request $request): Response
    {
        [$billable, $plan, $price] = $this->links->resolve($request);

        return response($this->views->make('billing::pay.show', [
            'billable' => $billable,
            'plan' => $plan,
            'price' => $price,
            'options' => $this->options($plan->currency),
            'paidUntil' => $billable->paidUntil($plan),
            'action' => $request->fullUrl(),
        ]))->withHeaders(self::PRIVATE_HEADERS);
    }

    public function start(Request $request, OnlineCheckout $checkout): RedirectResponse
    {
        [$billable, $plan, $price] = $this->links->resolve($request);

        $data = $request->validate([
            'choice' => ['required', 'string', 'max:140', 'regex:/^\d+:[a-z0-9_\-]+$/'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        [$instanceId, $method] = explode(':', (string) $data['choice'], 2);

        $gateway = $this->catalog->instances($plan->currency)->firstWhere('id', (int) $instanceId);
        if (! $gateway instanceof GatewayInstance) {
            return back()->withErrors(['choice' => __('billing::billing.errors.gateway_inactive')]);
        }

        try {
            $started = $checkout->start(new CheckoutRequest(
                billable: $billable,
                plan: $plan,
                price: $price,
                gateway: $gateway,
                method: $method,
                email: $data['email'] ?? null,
                phone: $data['phone'] ?? null,
            ));
        } catch (BillingException $e) {
            if ($e instanceof GatewayError) {
                Log::warning('billing: pay link checkout failed', ['gateway_instance_id' => $gateway->id]);
            }

            return back()->withInput($request->only('choice', 'email', 'phone'))->withErrors(['choice' => $e->getMessage()]);
        }

        return redirect()->away($started->redirectUrl);
    }

    /**
     * @return list<array{instance: GatewayInstance, online: bool, methods: list<PaymentMethod>, fields: list<string>, instructions: ?string}>
     */
    private function options(string $currency): array
    {
        return $this->catalog->instances($currency)
            ->map(function (GatewayInstance $instance): array {
                $provider = $instance->gateway();

                return [
                    'instance' => $instance,
                    'online' => $provider instanceof ChargesOnce,
                    'methods' => $instance->offeredMethods(),
                    'fields' => $provider instanceof OmnipayProvider ? $provider::payerFields() : [],
                    'instructions' => $provider instanceof ChargesOnce ? null : $instance->setting('instructions'),
                ];
            })
            ->values()
            ->all();
    }
}
