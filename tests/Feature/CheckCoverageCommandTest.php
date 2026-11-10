<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Ivanange\Billing\Events\CoverageExpiring;
use Ivanange\Billing\Events\CoverageLapsed;
use Ivanange\Billing\Tests\TestCase;

class CheckCoverageCommandTest extends TestCase
{
    public function test_expiring_and_lapsed_events_fire_once_per_cover_end(): void
    {
        Event::fake([CoverageExpiring::class, CoverageLapsed::class]);
        $plan = $this->plan(['grace_days' => 1]);
        $gateway = $this->gateway();
        $this->record(['billables' => [$this->member('Ends soon')], 'plan' => $plan, 'gateway' => $gateway, 'startsOn' => CarbonImmutable::parse('2026-02-12')]); // ends 11 Mar
        $this->record(['billables' => [$this->member('Lapsed')], 'plan' => $plan, 'gateway' => $gateway, 'startsOn' => CarbonImmutable::parse('2026-02-05')]);   // ends 4 Mar, grace to 5 Mar
        $this->record(['billables' => [$this->member('Long ago')], 'plan' => $plan, 'gateway' => $gateway, 'startsOn' => CarbonImmutable::parse('2026-01-01')]);   // lapsed 1 Feb: too old to announce
        $this->record(['billables' => [$this->member('Fine')], 'plan' => $plan, 'gateway' => $gateway, 'term' => $this->months(6)]);

        $this->artisan('billing:check-coverage')->expectsOutput('Expiring: 1, lapsed: 1.')->assertSuccessful();
        $this->artisan('billing:check-coverage')->expectsOutput('Expiring: 0, lapsed: 0.')->assertSuccessful();

        Event::assertDispatchedTimes(CoverageExpiring::class, 1);
        Event::assertDispatched(CoverageExpiring::class, fn (CoverageExpiring $e): bool => $e->subscription->billable->name === 'Ends soon');
        Event::assertDispatched(CoverageLapsed::class, fn (CoverageLapsed $e): bool => $e->subscription->billable->name === 'Lapsed');
        Event::assertDispatchedTimes(CoverageLapsed::class, 1);
    }

    public function test_renewing_allows_a_new_reminder_for_the_new_end_date(): void
    {
        Event::fake([CoverageExpiring::class]);
        $member = $this->member();
        $plan = $this->plan();
        $gateway = $this->gateway();
        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway, 'startsOn' => CarbonImmutable::parse('2026-02-12')]);

        $this->artisan('billing:check-coverage');
        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway]);
        $this->artisan('billing:check-coverage', ['--date' => '2026-04-09']);

        Event::assertDispatchedTimes(CoverageExpiring::class, 2);
    }
}
