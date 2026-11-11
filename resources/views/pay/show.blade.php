@extends('billing::pay.layout')

@section('title', __('billing::billing.pay.title'))

@section('content')
    <div class="card">
        <h1>{{ $plan->name }} – {{ $price->label }}</h1>
        <p class="muted">{{ __('billing::billing.pay.for', ['name' => $billable->billingName()]) }}</p>
        <p class="amount">{{ \Ivanange\Billing\Support\Money::format((int) $price->amount, $plan->currency) }}</p>
        @if ($paidUntil)
            <p class="muted">{{ __('billing::billing.pay.paid_until', ['date' => $paidUntil->isoFormat('LL')]) }}</p>
        @endif
    </div>

    @php($online = collect($options)->where('online', true)->values())
    @php($offline = collect($options)->where('online', false)->values())
    @php($fields = $online->pluck('fields')->flatten()->unique()->values()->all())

    @if ($online->isEmpty() && $offline->isEmpty())
        <div class="card"><p>{{ __('billing::billing.pay.no_gateways') }}</p></div>
    @endif

    @if ($online->isNotEmpty())
        <form method="POST" action="{{ $action }}" class="card">
            @csrf
            <h1>{{ __('billing::billing.pay.choose') }}</h1>
            @error('choice')<p class="error" role="alert">{{ $message }}</p>@enderror
            <fieldset>
                <legend class="muted">{{ __('billing::billing.pay.method') }}</legend>
                @foreach ($online as $option)
                    @foreach ($option['methods'] as $method)
                        @php($value = $option['instance']->id.':'.$method->key)
                        <label class="option">
                            <input type="radio" name="choice" value="{{ $value }}" required @checked(old('choice') === $value)>
                            <span>{{ $method->label() }} <span class="muted">· {{ $option['instance']->name }}</span></span>
                        </label>
                    @endforeach
                @endforeach
            </fieldset>
            @if (in_array('email', $fields, true))
                <p>
                    <label for="email">{{ __('billing::billing.pay.email') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="254">
                    @error('email')<span class="error">{{ $message }}</span>@enderror
                </p>
            @endif
            @if (in_array('phone', $fields, true))
                <p>
                    <label for="phone">{{ __('billing::billing.pay.phone') }}</label>
                    <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" maxlength="32">
                    @error('phone')<span class="error">{{ $message }}</span>@enderror
                </p>
            @endif
            <button type="submit">{{ __('billing::billing.pay.submit') }}</button>
        </form>
    @endif

    @foreach ($offline as $option)
        <div class="card">
            <h1>{{ $option['instance']->name }}</h1>
            <p class="muted">{{ collect($option['methods'])->map(fn ($m) => $m->label())->implode(', ') }}</p>
            @if (filled($option['instructions']))
                <div class="instructions">{{ $option['instructions'] }}</div>
            @else
                <p class="muted">{{ __('billing::billing.pay.offline_no_instructions') }}</p>
            @endif
        </div>
    @endforeach
@endsection
