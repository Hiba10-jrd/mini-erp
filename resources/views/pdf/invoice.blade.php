<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">

    <title>{{ __('Facture') }} {{ $invoice->number }}</title>

    <style>
        @page {
            margin: 32px 38px 40px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #1f2937;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.45;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .header-table td {
            vertical-align: top;
        }

        .logo {
            max-height: 62px;
            max-width: 180px;
            margin-bottom: 10px;
        }

        .company-name {
            margin: 0 0 4px;
            color: #111827;
            font-size: 16px;
            font-weight: bold;
        }

        .invoice-title {
            margin: 0;
            color: #111827;
            font-size: 25px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .invoice-number {
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
            height: 2px;
            margin: 18px 0;
            background: #111827;
        }

        .info-table {
            margin-bottom: 22px;
        }

        .info-table td {
            width: 50%;
            padding-right: 16px;
            vertical-align: top;
        }

        .section-label {
            margin-bottom: 6px;
            color: #6b7280;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: .8px;
            text-transform: uppercase;
        }

        .customer-name {
            margin-bottom: 4px;
            color: #111827;
            font-size: 14px;
            font-weight: bold;
        }

        .document-info td {
            padding: 3px 0;
        }

        .document-info td:first-child {
            color: #6b7280;
        }

        .items {
            margin-top: 6px;
        }

        .items th {
            padding: 8px 6px;
            border-bottom: 1px solid #d1d5db;
            background: #f3f4f6;
            color: #4b5563;
            font-size: 8px;
            text-transform: uppercase;
        }

        .items td {
            padding: 9px 6px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .description {
            color: #111827;
            font-weight: bold;
        }

        .reference {
            margin-top: 2px;
            color: #6b7280;
            font-size: 8px;
        }

        .totals-wrapper {
            margin-top: 16px;
        }

        .totals {
            margin-left: auto;
            width: 42%;
        }

        .totals td {
            padding: 5px 7px;
        }

        .totals .label {
            color: #6b7280;
        }

        .totals .value {
            text-align: right;
            font-weight: bold;
        }

        .totals .grand-total td {
            padding-top: 8px;
            border-top: 1px solid #111827;
            color: #111827;
            font-size: 13px;
            font-weight: bold;
        }

        .text-section {
            margin-top: 22px;
            page-break-inside: avoid;
        }

        .text-section h3 {
            margin: 0 0 6px;
            color: #111827;
            font-size: 10px;
        }

        .text-section p {
            margin: 0;
            color: #4b5563;
            white-space: pre-line;
        }

        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            color: #9ca3af;
            font-size: 8px;
            text-align: center;
        }
    </style>
</head>

<body>
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                @if ($logoData)
                    <img
                        src="{{ $logoData }}"
                        class="logo"
                        alt="Logo"
                    >
                @endif

                <p class="company-name">
                    {{
                        $invoice->company_trade_name
                            ?: $invoice->company_legal_name
                    }}
                </p>

                @if (
                    $invoice->company_trade_name
                    && $invoice->company_legal_name
                )
                    <div class="muted">
                        {{ $invoice->company_legal_name }}
                    </div>
                @endif

                @if ($invoice->company_address)
                    <div class="muted">
                        {{ $invoice->company_address }}
                    </div>
                @endif

                @if ($invoice->company_phone)
                    <div class="muted">
                        {{ $invoice->company_phone }}
                    </div>
                @endif

                @if ($invoice->company_email)
                    <div class="muted">
                        {{ $invoice->company_email }}
                    </div>
                @endif
            </td>

            <td
                style="width: 40%;"
                class="right"
            >
                <p class="invoice-title">
                    {{ __('FACTURE') }}
                </p>

                <div class="invoice-number">
                    {{ $invoice->number }}
                </div>

                <div style="margin-top: 10px;">
                    {{ __('Date') }} :
                    <strong>
                        {{ $invoice->invoice_date->format('d/m/Y') }}
                    </strong>
                </div>

                <div>
                    {{ __('Échéance') }} :
                    <strong>
                        {{ $invoice->due_date?->format('d/m/Y') ?? '—' }}
                    </strong>
                </div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <table class="info-table">
        <tr>
            <td>
                <div class="section-label">
                    {{ __('Facturé à') }}
                </div>

                <div class="customer-name">
                    {{ $invoice->customer_name }}
                </div>

                @if ($invoice->customer_trade_name)
                    <div>
                        {{ $invoice->customer_trade_name }}
                    </div>
                @endif

                @if ($invoice->customer_address)
                    <div class="muted">
                        {{ $invoice->customer_address }}
                    </div>
                @endif

                @if ($invoice->customer_city || $invoice->customer_country)
                    <div class="muted">
                        {{
                            collect([
                                $invoice->customer_city,
                                $invoice->customer_country,
                            ])->filter()->implode(', ')
                        }}
                    </div>
                @endif

                @if ($invoice->customer_email)
                    <div class="muted">
                        {{ $invoice->customer_email }}
                    </div>
                @endif

                @if ($invoice->customer_phone)
                    <div class="muted">
                        {{ $invoice->customer_phone }}
                    </div>
                @endif

                @if ($invoice->customer_ice)
                    <div class="muted">
                        {{ __('ICE') }} :
                        {{ $invoice->customer_ice }}
                    </div>
                @endif

                @if ($invoice->customer_tax_id)
                    <div class="muted">
                        {{ __('IF') }} :
                        {{ $invoice->customer_tax_id }}
                    </div>
                @endif

                @if ($invoice->customer_commercial_register)
                    <div class="muted">
                        {{ __('RC') }} :
                        {{ $invoice->customer_commercial_register }}
                    </div>
                @endif
            </td>

            <td>
                <div class="section-label">
                    {{ __('Informations') }}
                </div>

                <table class="document-info">
                    @if ($invoice->salesOrder)
                        <tr>
                            <td>{{ __('Commande') }}</td>

                            <td class="right">
                                <strong>
                                    {{ $invoice->salesOrder->number }}
                                </strong>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td>{{ __('Statut') }}</td>

                        <td class="right">
                            <strong>{{ __('Émise') }}</strong>
                        </td>
                    </tr>

                    <tr>
                        <td>{{ __('Émise le') }}</td>

                        <td class="right">
                            <strong>
                                {{ $invoice->issued_at?->format('d/m/Y H:i') ?? '—' }}
                            </strong>
                        </td>
                    </tr>

                    <tr>
                        <td>{{ __('Émise par') }}</td>

                        <td class="right">
                            <strong>
                                {{ $invoice->issuer?->name ?? '—' }}
                            </strong>
                        </td>
                    </tr>

                    @if ($invoice->payment_term_label)
                        <tr>
                            <td>{{ __('Paiement') }}</td>

                            <td class="right">
                                <strong>
                                    {{ $invoice->payment_term_label }}
                                </strong>
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 31%;">
                    {{ __('Désignation') }}
                </th>

                <th
                    style="width: 10%;"
                    class="right"
                >
                    {{ __('Qté') }}
                </th>

                <th style="width: 8%;">
                    {{ __('Unité') }}
                </th>

                <th
                    style="width: 12%;"
                    class="right"
                >
                    {{ __('PU HT') }}
                </th>

                <th
                    style="width: 10%;"
                    class="right"
                >
                    {{ __('Remise') }}
                </th>

                <th
                    style="width: 9%;"
                    class="right"
                >
                    {{ __('TVA') }}
                </th>

                <th
                    style="width: 10%;"
                    class="right"
                >
                    {{ __('HT') }}
                </th>

                <th
                    style="width: 10%;"
                    class="right"
                >
                    {{ __('TTC') }}
                </th>
            </tr>
        </thead>

        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        <div class="description">
                            {{ $item->description }}
                        </div>

                        <div class="reference">
                            {{ $item->reference ?? '—' }}
                        </div>
                    </td>

                    <td class="right">
                        {{ $item->quantity }}
                    </td>

                    <td>
                        {{ $item->unit_label ?? '—' }}
                    </td>

                    <td class="right">
                        {{ number_format((float) $item->unit_price, 2, ',', ' ') }}
                    </td>

                    <td class="right">
                        {{ number_format((float) $item->discount_percent, 2, ',', ' ') }} %
                    </td>

                    <td class="right">
                        {{ number_format((float) $item->tax_rate_percent, 2, ',', ' ') }} %
                    </td>

                    <td class="right">
                        {{ number_format((float) $item->subtotal_ht, 2, ',', ' ') }}
                    </td>

                    <td class="right">
                        <strong>
                            {{ number_format((float) $item->total_ttc, 2, ',', ' ') }}
                        </strong>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals-wrapper">
        <table class="totals">
            <tr>
                <td class="label">
                    {{ __('Brut HT') }}
                </td>

                <td class="value">
                    {{ number_format((float) $invoice->subtotal_ht, 2, ',', ' ') }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    {{ __('Remises') }}
                </td>

                <td class="value">
                    {{ number_format((float) $invoice->discount_total, 2, ',', ' ') }}
                </td>
            </tr>

            <tr>
                <td class="label">
                    {{ __('TVA') }}
                </td>

                <td class="value">
                    {{ number_format((float) $invoice->tax_total, 2, ',', ' ') }}
                </td>
            </tr>

            <tr class="grand-total">
                <td>
                    {{ __('Total TTC') }}
                </td>

                <td class="right">
                    {{ number_format((float) $invoice->total_ttc, 2, ',', ' ') }}
                </td>
            </tr>
        </table>
    </div>

    @if ($invoice->terms)
        <div class="text-section">
            <h3>{{ __('Conditions') }}</h3>

            <p>{{ $invoice->terms }}</p>
        </div>
    @endif

    @if ($invoice->notes)
        <div class="text-section">
            <h3>{{ __('Notes') }}</h3>

            <p>{{ $invoice->notes }}</p>
        </div>
    @endif

    <div class="footer">
        {{ $invoice->number }}

        @if ($invoice->company_ice)
            · {{ __('ICE') }} {{ $invoice->company_ice }}
        @endif

        @if ($invoice->company_tax_id)
            · {{ __('IF') }} {{ $invoice->company_tax_id }}
        @endif

        @if ($invoice->company_commercial_register)
            · {{ __('RC') }}
            {{ $invoice->company_commercial_register }}
        @endif
    </div>
</body>
</html>