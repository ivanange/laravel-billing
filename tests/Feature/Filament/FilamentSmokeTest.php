<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Filament;

use Ivanange\Billing\Filament\Resources\Calendars\BillingCalendarResource;
use Ivanange\Billing\Filament\Resources\GatewayInstances\GatewayInstanceResource;
use Ivanange\Billing\Filament\Resources\Payments\PaymentResource;
use Ivanange\Billing\Filament\Resources\Plans\PlanResource;
use Ivanange\Billing\Filament\Resources\Subscriptions\SubscriptionResource;
use Ivanange\Billing\Tests\FilamentTestCase;

class FilamentSmokeTest extends FilamentTestCase
{
    public function test_every_resource_page_renders(): void
    {
        $payment = $this->record();
        $plan = $payment->coveragePeriods()->first()->subscription->plan;

        $urls = [
            PaymentResource::getUrl('index'),
            PaymentResource::getUrl('view', ['record' => $payment]),
            SubscriptionResource::getUrl('index'),
            PlanResource::getUrl('index'),
            PlanResource::getUrl('create'),
            PlanResource::getUrl('edit', ['record' => $plan]),
            BillingCalendarResource::getUrl('index'),
            BillingCalendarResource::getUrl('create'),
            GatewayInstanceResource::getUrl('index'),
            GatewayInstanceResource::getUrl('create'),
            GatewayInstanceResource::getUrl('edit', ['record' => $payment->gatewayInstance]),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }
}
