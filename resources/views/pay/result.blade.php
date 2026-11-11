@extends('billing::pay.layout')

@section('title', __('billing::billing.pay.result_title'))

@section('content')
    @php($status = $payment->status)
    <div class="card">
        <h1 class="status-{{ $status->value }}">
            @switch($status)
                @case(\Ivanange\Billing\Enums\PaymentStatus::Confirmed) {{ __('billing::billing.pay.result.confirmed') }} @break
                @case(\Ivanange\Billing\Enums\PaymentStatus::Failed) {{ __('billing::billing.pay.result.failed') }} @break
                @case(\Ivanange\Billing\Enums\PaymentStatus::Pending) {{ __('billing::billing.pay.result.pending') }} @break
                @default {{ $status->label() }}
            @endswitch
        </h1>
        <p class="amount">{{ $payment->formattedAmount() }}</p>
        <p class="muted">{{ __('billing::billing.pay.reference', ['reference' => $payment->reference]) }}</p>
        @if (! $checked)
            <p class="muted">{{ __('billing::billing.pay.result.unchecked') }}</p>
        @endif
        @if ($status === \Ivanange\Billing\Enums\PaymentStatus::Pending)
            <p class="muted">{{ __('billing::billing.pay.result.pending_help') }}</p>
        @endif
    </div>
@endsection
