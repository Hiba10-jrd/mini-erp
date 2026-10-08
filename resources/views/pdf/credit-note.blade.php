<!DOCTYPE html>
<html lang="{{ $pdfLocale ?? 'fr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('Avoir') }} {{ $creditNote->number }}</title>

    <style>
        @page { margin: 34px 38px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 10px;
            line-height: 1.45;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .top td {
            vertical-align: top;
        }

        .logo {
            max-width: 170px;
            max-height: 60px;
            margin-bottom: 10px;
        }

        h1 {
            margin: 0;
            font-size: 25px;
            color: #111827;
        }

        .number {
            margin-top: 5px;
            color: #4f46e5;
            font-size: 13px;
            font-weight: bold;
        }

        .muted {
            color: #6b7280;
        }

        .right {
            text-align: right;
        }

        .divider {
            border-top: 2px solid #111827;
            margin: 18px 0;
        }

        .items th {
            background: #f3f4f6;
            color: #4b5563;
            padding: 8px 6px;
            font-size: 8px;
            text-transform: uppercase;
        }

        .items td {
            border-bottom: 1px solid #e5e7eb;
            padding: 9px 6px;
        }

        .totals {
            width: 42%;
            margin: 16px 0 0 auto;
        }

        .totals td {
            padding: 5px 7px;
        }

        .grand td {
            border-top: 1px solid #111827;
            padding-top: 8px;
            font-size: 13px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <table class="top">
        <tr>
            <td>
                @if ($logoData)
                    <img src="{{ $logoData }}" class="logo" alt="Logo">
                @endif

                <strong style="font-size: 15px;">
                    {{ $invoice->company_trade_name ?: $invoice->company_legal_name }}
                </strong>

                @if ($invoice->company_address)
                    <div class="muted">{{ $invoice->company_address }}</div>
                @endif

                @if ($invoice->company_phone)
                    <div class="muted">{{ $invoice->company_phone }}</div>
                @endif

                @if ($invoice->company_email)
                    <div class="muted">{{ $invoice->company_email }}</div>
                @endif
            </td>

            <td class="right">
                <h1>{{ __('AVOIR') }}</h1>
                <div class="number">{{ $creditNote->number }}</div>

                <div style="margin-top: 10px;">
                    {{ __('Date') }} :
                    <strong>{{ $creditNote->credit_date->format('d/m/Y') }}</strong>
                </div>

                <div>
                    {{ __('Facture d’origine') }} :
                    <strong>{{ $invoice->number }}</strong>
                </div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <table style="margin-bottom: 22px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                <div class="muted">{{ __('Client') }}</div>
                <strong style="font-size: 14px;">{{ $creditNote->customer_name }}</strong>

                @if ($creditNote->customer_address)
                    <div>{{ $creditNote->customer_address }}</div>
                @endif

                @if ($creditNote->customer_city || $creditNote->customer_country)
                    <div class="muted">
                        {{
                            collect([
                                $creditNote->customer_city,
                                $creditNote->customer_country,
                            ])->filter()->implode(', ')
                        }}
                    </div>
                @endif

                @if ($creditNote->customer_ice)
                    <div class="muted">ICE : {{ $creditNote->customer_ice }}</div>
                @endif
            </td>

            <td class="right" style="vertical-align: top;">
                @if ($creditNote->reason)
                    <div class="muted">{{ __('Motif') }}</div>
                    <strong>{{ $creditNote->reason }}</strong>
                @endif

                @if ($creditNote->issued_at)
                    <div style="margin-top: 10px;">
                        {{ __('Émis le') }} :
                        {{ $creditNote->issued_at->format('d/m/Y H:i') }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>{{ __('Désignation') }}</th>
                <th class="right">{{ __('Qté') }}</th>
                <th class="right">{{ __('PU HT') }}</th>
                <th class="right">{{ __('Remise') }}</th>
                <th class="right">{{ __('TVA') }}</th>
                <th class="right">{{ __('HT') }}</th>
                <th class="right">{{ __('TTC') }}</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($creditNote->items as $item)
                <tr>
                    <td>
                        <strong>{{ $item->description }}</strong>
                        <div class="muted">{{ $item->reference ?? '—' }}</div>
                    </td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ number_format((float) $item->unit_price, 2, ',', ' ') }}</td>
                    <td class="right">{{ $item->discount_percent }} %</td>
                    <td class="right">{{ $item->tax_rate_percent }} %</td>
                    <td class="right">{{ number_format((float) $item->subtotal_ht, 2, ',', ' ') }}</td>
                    <td class="right"><strong>{{ number_format((float) $item->total_ttc, 2, ',', ' ') }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">{{ __('Brut HT') }}</td>
            <td class="right">{{ number_format((float) $creditNote->subtotal_ht, 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('Remises') }}</td>
            <td class="right">{{ number_format((float) $creditNote->discount_total, 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('TVA') }}</td>
            <td class="right">{{ number_format((float) $creditNote->tax_total, 2, ',', ' ') }}</td>
        </tr>
        <tr class="grand">
            <td>{{ __('Total TTC') }}</td>
            <td class="right">{{ number_format((float) $creditNote->total_ttc, 2, ',', ' ') }}</td>
        </tr>
    </table>

    @if ($creditNote->notes)
        <div style="margin-top: 24px;">
            <strong>{{ __('Notes') }}</strong>
            <p class="muted">{{ $creditNote->notes }}</p>
        </div>
    @endif
</body>
</html>