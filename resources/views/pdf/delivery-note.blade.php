<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __('Bon de livraison') }} {{ $deliveryNote->number }}</title>
    <style>
        @page { margin: 34px 38px; }
        body { color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h1 { color: #1d4ed8; font-size: 23px; margin: 0; }
        h2 { color: #1d4ed8; font-size: 12px; margin: 0 0 7px; }
        p { margin: 3px 0; }
        .top { border-bottom: 2px solid #1d4ed8; padding-bottom: 15px; }
        .brand { display: table; width: 100%; }
        .brand-cell { display: table-cell; vertical-align: top; width: 50%; }
        .logo { max-height: 58px; max-width: 170px; margin-bottom: 8px; }
        .meta { color: #526273; line-height: 1.5; }
        .columns { display: table; width: 100%; margin: 20px 0; }
        .column { display: table-cell; vertical-align: top; width: 50%; }
        .label { color: #667586; font-size: 8px; font-weight: bold; text-transform: uppercase; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #eff6ff; color: #1e3a8a; font-size: 8px; text-align: left; }
        th, td { border-bottom: 1px solid #dbeafe; padding: 7px 5px; }
        .right { text-align: right; }
        .totals { margin: 16px 0 0 auto; width: 52%; }
        .totals td { border: 0; padding: 4px; }
        .grand { border-top: 1px solid #1d4ed8 !important; color: #1d4ed8; font-size: 13px; font-weight: bold; }
        .section { margin-top: 20px; }
        .muted { color: #667586; }
        .footer { border-top: 1px solid #dbeafe; color: #667586; font-size: 8px; margin-top: 24px; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="top">
        <div class="brand">
            <div class="brand-cell">
                @if ($logoData)<img class="logo" src="{{ $logoData }}" alt="{{ __('Logo') }}">@endif
                <h2>{{ $company?->trade_name ?: $company?->legal_name ?: config('app.name') }}</h2>
                @if ($company?->legal_name && $company->trade_name)<p>{{ $company->legal_name }}</p>@endif
                @if ($company?->address)<p>{{ $company->address }}</p>@endif
                <p>{{ collect([$company?->phone, $company?->email, $company?->website])->filter()->implode(' · ') }}</p>
            </div>
            <div class="brand-cell right">
                <h1>{{ __('BL') }}</h1>
                <p><strong>{{ $deliveryNote->number }}</strong></p>
                <p>{{ __('Date de livraison') }} : {{ $deliveryNote->delivery_date->format('d/m/Y') }}</p>
                <p>{{ __('Commande') }} : {{ $deliveryNote->salesOrder->number }}</p>
                <p>{{ __('Statut') }} : {{ __($deliveryNote->status) }}</p>
            </div>
        </div>
    </div>

    <div class="columns">
        <div class="column">
            <p class="label">{{ __('Client') }}</p>
            <h2>{{ $deliveryNote->salesOrder->customer_name }}</h2>
            @if ($deliveryNote->salesOrder->customer_trade_name)<p>{{ $deliveryNote->salesOrder->customer_trade_name }}</p>@endif
            @if ($deliveryNote->salesOrder->customer_address)<p>{{ $deliveryNote->salesOrder->customer_address }}</p>@endif
            <p>{{ collect([$deliveryNote->salesOrder->customer_city, $deliveryNote->salesOrder->customer_country])->filter()->implode(', ') }}</p>
            @if ($deliveryNote->salesOrder->customer_email)<p>{{ $deliveryNote->salesOrder->customer_email }}</p>@endif
            @if ($deliveryNote->salesOrder->customer_phone)<p>{{ $deliveryNote->salesOrder->customer_phone }}</p>@endif
        </div>
        <div class="column right">
            <p class="label">{{ __('Dépôt') }}</p>
            <p>{{ $deliveryNote->warehouse?->name ?? '—' }}</p>
            <p>{{ $deliveryNote->warehouse?->code ?? '—' }}</p>
            <p class="label" style="margin-top: 12px;">{{ __('Préparé par') }}</p>
            <p>{{ $deliveryNote->creator?->name ?? '—' }}</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('Désignation') }}</th>
                <th class="right">{{ __('Qté') }}</th>
                <th>{{ __('Unité') }}</th>
                <th>{{ __('Référence') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($deliveryNote->items as $item)
                <tr>
                    <td><strong>{{ $item->description }}</strong><br><span class="muted">{{ $item->item_type === 'service' ? __('Service') : __('Produit') }}</span></td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td>{{ $item->unit_label ?? '—' }}</td>
                    <td>{{ $item->reference ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($deliveryNote->notes)
        <div class="section">
            <h2>{{ __('Notes') }}</h2>
            <p>{!! nl2br(e($deliveryNote->notes)) !!}</p>
        </div>
    @endif

    <div class="footer">
        {{ collect([$company?->bank_name, $company?->bank_account_holder, $company?->bank_reference])->filter()->implode(' · ') }}
    </div>
</body>
</html>
