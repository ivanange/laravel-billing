<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\PlanPrice;

/**
 * Self-service pay links: a signed, expiring URL naming who is paid for
 * and which price preset. The signature stops anyone from changing the
 * billable, plan or price in the URL; the amount always comes from the
 * price preset in the database.
 */
class PayLinks
{
    public function make(Model&Billable $billable, Plan $plan, PlanPrice $price, ?DateTimeInterface $expiresAt = null): string
    {
        if ((int) $price->plan_id !== (int) $plan->getKey()) {
            throw BillingException::because('price_not_in_plan');
        }

        if (! $plan->accepts($billable)) {
            throw BillingException::because('plan_does_not_accept', ['billable' => $billable->billingName()]);
        }

        if ($price->isCalendar()) {
            throw BillingException::because('calendar_price_offline_only');
        }

        $expiresAt ??= now()->addDays((int) config('billing.pay_links.expires_after_days', 7));

        return URL::temporarySignedRoute('billing.pay.show', $expiresAt, [
            'billable' => $billable->getMorphClass(),
            'id' => $billable->getKey(),
            'plan' => $plan->getKey(),
            'price' => $price->getKey(),
        ]);
    }

    /**
     * Read a (signature-checked) pay link request. 404 when anything it
     * names no longer exists.
     *
     * @return array{0: Model&Billable, 1: Plan, 2: PlanPrice}
     */
    public function resolve(Request $request): array
    {
        $type = $request->query('billable');
        $id = $request->query('id');
        abort_unless(is_string($type) && is_scalar($id), 404);

        $class = Relation::getMorphedModel($type) ?? $type;

        abort_unless(is_a($class, Model::class, true) && is_a($class, Billable::class, true), 404);

        /** @var (Model&Billable)|null $billable */
        $billable = $class::query()->find($id);
        $plan = Plan::query()->active()->find($request->query('plan'));
        $price = $plan?->prices()->whereKey($request->query('price'))->first();

        abort_if($billable === null || $plan === null || $price === null, 404);

        return [$billable, $plan, $price];
    }
}
