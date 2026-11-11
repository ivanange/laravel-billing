<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Services\PayLinks;
use Livewire\Component;

/**
 * Generates a signed self-service pay link for one billable and copies it
 * to the clipboard. Use as a row or header action on a billable resource;
 * in a relation manager pass ->billableUsing(fn ($livewire) => $livewire->getOwnerRecord()).
 */
class PayLinkAction extends Action
{
    private ?Closure $billableResolver = null;

    public static function getDefaultName(): ?string
    {
        return 'payLink';
    }

    public function billableUsing(Closure $resolver): static
    {
        $this->billableResolver = $resolver;

        return $this;
    }

    public function resolveBillable(): (Model&Billable)|null
    {
        $billable = $this->billableResolver === null ? $this->getRecord() : $this->evaluate($this->billableResolver);

        return $billable instanceof Model && $billable instanceof Billable ? $billable : null;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('billing::billing.ui.pay_link.action'))
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->modalHeading(fn (): string => __('billing::billing.ui.pay_link.heading', ['name' => $this->resolveBillable()?->billingName()]))
            ->modalSubmitActionLabel(__('billing::billing.ui.pay_link.submit'))
            ->visible(fn (): bool => Gate::allows('billing.record', $this->resolveBillable()))
            ->schema(fn (): array => $this->fields())
            ->action(fn (array $data, Component $livewire, Action $action) => $this->generate($data, $livewire, $action));
    }

    /**
     * @return list<mixed>
     */
    private function fields(): array
    {
        $billable = $this->resolveBillable();

        return [
            Select::make('plan_id')
                ->label(__('billing::billing.ui.pay_link.plan'))
                ->options(fn (): array => $billable === null ? [] : Plan::query()->active()->for($billable)->pluck('name', 'id')->all())
                ->required()
                ->live(),
            Select::make('price_id')
                ->label(__('billing::billing.ui.pay_link.price'))
                ->options(fn (Get $get): array => Plan::query()->find($get('plan_id'))?->prices()->whereNull('calendar_id')->pluck('label', 'id')->all() ?? [])
                ->required(),
            TextInput::make('days')
                ->label(__('billing::billing.ui.pay_link.expires_days'))
                ->numeric()
                ->minValue(1)
                ->maxValue(90)
                ->default((int) config('billing.pay_links.expires_after_days', 7))
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function generate(array $data, Component $livewire, Action $action): void
    {
        $billable = $this->resolveBillable();
        Gate::authorize('billing.record', $billable);
        $plan = Plan::query()->active()->find($data['plan_id'] ?? null);
        $price = $plan?->prices()->find($data['price_id'] ?? null);
        abort_if($billable === null || $plan === null || $price === null, 404);

        $expires = now()->addDays(max(1, min(90, (int) $data['days'])));

        try {
            $url = app(PayLinks::class)->make($billable, $plan, $price, $expires);
        } catch (BillingException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();

            return;
        }

        $livewire->js('navigator.clipboard && navigator.clipboard.writeText('.json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).')');

        Notification::make()
            ->success()
            ->title(__('billing::billing.ui.pay_link.done'))
            ->body(__('billing::billing.ui.pay_link.copy_help', ['date' => $expires->isoFormat('LL')])."\n".$url)
            ->persistent()
            ->send();
    }
}
