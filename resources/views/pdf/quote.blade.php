<!DOCTYPE html>
<html lang="{{ $pdfLocale ?? 'fr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Devis') }} {{ $quote->number }}</title>
    <style>
        @page { margin: 34px 38px; }
        body { color: #202a35; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h1 { color: #17324d; font-size: 23px; margin: 0; }
        h2 { color: #17324d; font-size: 12px; margin: 0 0 7px; }
        p { margin: 3px 0; }
        .top { border-bottom: 2px solid #17324d; padding-bottom: 15px; }
        .brand { display: table; width: 100%; }
        .brand-cell { display: table-cell; vertical-align: top; width: 50%; }
        .logo { max-height: 58px; max-width: 170px; margin-bottom: 8px; }
        .meta { color: #526273; line-height: 1.5; }
        .columns { display: table; width: 100%; margin: 20px 0; }
        .column { display: table-cell; vertical-align: top; width: 50%; }
        .label { color: #667586; font-size: 8px; font-weight: bold; text-transform: uppercase; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #edf2f6; color: #30475d; font-size: 8px; text-align: left; }
        th, td { border-bottom: 1px solid #d9e0e6; padding: 7px 5px; }
        .right { text-align: right; }
        .totals { margin: 16px 0 0 auto; width: 48%; }
        .totals td { border: 0; padding: 4px; }
        .grand { border-top: 1px solid #17324d !important; color: #17324d; font-size: 13px; font-weight: bold; }
        .section { margin-top: 20px; }
        .muted { color: #667586; }
        .footer { border-top: 1px solid #d9e0e6; color: #667586; font-size: 8px; margin-top: 24px; padding-top: 8px; }
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
                <h1>{{ __('DEVIS') }}</h1>
                <p><strong>{{ $quote->number }}</strong></p>
                <p>{{ __('Date') }} : {{ $quote->quote_date->format('d/m/Y') }}</p>
                <p>{{ __('Valide jusqu’au') }} : {{ $quote->valid_until?->format('d/m/Y') ?? '—' }}</p>
                <p>{{ __('Statut') }} : {{ __(ucfirst($quote->status)) }}</p>
            </div>
        </div>
        <p class="meta">
            @if ($company?->ice){{ __('ICE') }} : {{ $company->ice }} · @endif
            @if ($company?->tax_id){{ __('Identifiant fiscal') }} : {{ $company->tax_id }} · @endif
            @if ($company?->commercial_register){{ __('Registre de commerce') }} : {{ $company->commercial_register }}@endif
        </p>
    </div>

    <div class="columns">
        <div class="column">
            <p class="label">{{ __('Client') }}</p>
            <h2>{{ $quote->customer_name }}</h2>
            @if ($quote->customer_trade_name)<p>{{ $quote->customer_trade_name }}</p>@endif
            @if ($quote->customer_address)<p>{{ $quote->customer_address }}</p>@endif
            <p>{{ collect([$quote->customer_city, $quote->customer_country])->filter()->implode(', ') }}</p>
            @if ($quote->customer_email)<p>{{ $quote->customer_email }}</p>@endif
            @if ($quote->customer_phone)<p>{{ $quote->customer_phone }}</p>@endif
            @if ($quote->customer_ice)<p>{{ __('ICE') }} : {{ $quote->customer_ice }}</p>@endif
            @if ($quote->customer_tax_id)<p>{{ __('Identifiant fiscal') }} : {{ $quote->customer_tax_id }}</p>@endif
            @if ($quote->customer_commercial_register)<p>{{ __('Registre de commerce') }} : {{ $quote->customer_commercial_register }}</p>@endif
        </div>
        <div class="column right">
            <p class="label">{{ __('Préparé par') }}</p>
            <p>{{ $quote->creator?->name ?? '—' }}</p>
            <p>{{ $quote->creator?->email ?? '' }}</p>
        </div>
    </div>

    <table>
        <thead><tr><th>{{ __('Désignation') }}</th><th class="right">{{ __('Quantité') }}</th><th>{{ __('Unité') }}</th><th class="right">{{ __('PU HT') }}</th><th class="right">{{ __('Remise') }}</th><th class="right">{{ __('TVA') }}</th><th class="right">{{ __('Total HT') }}</th><th class="right">{{ __('Total TTC') }}</th></tr></thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr>
                    <td><strong>{{ $item->description }}</strong><br><span class="muted">{{ $item->reference ?? '—' }}</span></td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td>{{ $item->unit_label ?? '—' }}</td>
                    <td class="right">{{ $item->unit_price }}</td>
                    <td class="right">{{ $item->discount_percent }} %<br><span class="muted">{{ $item->discount_amount }}</span></td>
                    <td class="right">{{ $item->tax_rate_percent }} %<br><span class="muted">{{ $item->tax_amount }}</span></td>
                    <td class="right">{{ $item->subtotal_ht }}</td>
                    <td class="right">{{ $item->total_ttc }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ __('Brut HT') }}</td><td class="right">{{ $quote->subtotal_ht }}</td></tr>
        <tr><td>{{ __('Remises') }}</td><td class="right">{{ $quote->discount_total }}</td></tr>
        <tr><td>{{ __('Base HT nette') }}</td><td class="right">{{ $quote->baseHt() }}</td></tr>
        <tr><td>{{ __('TVA') }}</td><td class="right">{{ $quote->tax_total }}</td></tr>
        <tr class="grand"><td>{{ __('Total TTC') }} {{ $commercialSetting?->currency_code }}</td><td class="right">{{ $quote->total_ttc }}</td></tr>
    </table>

    @if ($quote->terms)<div class="section"><h2>{{ __('Conditions') }}</h2><p>{!! nl2br(e($quote->terms)) !!}</p></div>@endif
    @if ($quote->notes)<div class="section"><h2>{{ __('Notes') }}</h2><p>{!! nl2br(e($quote->notes)) !!}</p></div>@endif

    <div class="footer">
        {{ collect([$company?->bank_name, $company?->bank_account_holder, $company?->bank_reference])->filter()->implode(' · ') }}
    </div>
</body>
</html>