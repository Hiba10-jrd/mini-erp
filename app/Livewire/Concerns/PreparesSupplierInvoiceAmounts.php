<?php

namespace App\Livewire\Concerns;

use App\Models\SupplierInvoice;
use App\Services\QuoteCalculator;
use App\Services\SupplierInvoiceReconciliationCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Shared form state and presentation only; financial validation stays in the existing service. */
trait PreparesSupplierInvoiceAmounts
{
    public string $netHt = '';

    public string $tax = '';

    public string $documentTtc = '';

    public string $rate = '';

    public string $taxReason = '';

    public string $roundingReason = '';

    public string $differenceTaxReason = '';

    public string $differenceReason = '';

    public string $differenceCategory = '';

    public string $differenceTaxRate = '';

    public string $disputeReason = '';

    public string $attachmentId = '';

    public bool $confirmDifferenceTaxTreatment = false;

    public bool $detailed = false;

    public bool $taxDetailsOpen = false;

    public array $breakdown = [];

    protected function initializeAmounts(SupplierInvoice $invoice): void
    {
        $rates = $invoice->items->pluck('tax_rate_percent')->unique();
        $this->rate = $rates->count() === 1 ? (string) $rates->first() : '';
        $reconciliation = $invoice->reconciliation;
        if ($reconciliation !== null) {
            $this->netHt = $reconciliation->net_ht;
            $this->tax = $reconciliation->tax_total;
            $this->documentTtc = $reconciliation->total_ttc;
            $this->attachmentId = (string) $reconciliation->attachment_id;
            $this->differenceReason = $reconciliation->difference_reason ?? '';
            $this->breakdown = $reconciliation->breakdown;
            $firstComponent = $this->breakdown[0] ?? [];
            $adjustmentComponent = $this->breakdown[1] ?? [];
            $simpleAdjustment = count($this->breakdown) === 2
                && ($firstComponent['kind'] ?? null) === 'purchase'
                && in_array($adjustmentComponent['kind'] ?? null, ['discount', 'transport', 'fee'], true)
                && ($firstComponent['net_ht'] ?? null) === (string) $invoice->baseHt()
                && ($firstComponent['tax'] ?? null) === (string) $invoice->tax_total
                && ($adjustmentComponent['rate'] ?? null) === ($firstComponent['rate'] ?? null);
            $this->detailed = ! $simpleAdjustment && (count($this->breakdown) !== 1 || $this->breakdown[0]['kind'] !== 'purchase');
            $this->rate = $this->breakdown[0]['rate'];
            $this->taxReason = $this->breakdown[0]['tax_reason'];
            $this->roundingReason = $this->breakdown[0]['rounding_reason'];
            $this->differenceCategory = match ($this->breakdown[1]['kind'] ?? null) {
                'transport' => 'delivery',
                'discount' => 'discount',
                'fee' => 'other_fees',
                default => $this->reasonCategory($this->differenceReason),
            };
            $reasonParts = explode(' — ', $this->differenceReason, 2);
            if (count($reasonParts) === 2 && $this->reasonCategory($this->differenceReason) !== '') {
                $this->differenceReason = $reasonParts[1];
            }
            $this->differenceTaxRate = $this->breakdown[1]['rate'] ?? $this->rate;
            $this->differenceTaxReason = $this->breakdown[1]['tax_reason'] ?? '';
        } else {
            $this->attachmentId = (string) ($invoice->attachments()->where('category', SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY)->latest('id')->value('id') ?? '');
        }
    }

    public function updatedNetHt(): void
    {
        $this->updateCalculatedTtc();
        $this->resetDifferenceTaxConfirmation();
    }

    public function updatedTax(): void
    {
        $this->updateCalculatedTtc();
        $this->resetDifferenceTaxConfirmation();
    }

    public function updatedRate(): void
    {
        $this->resetDifferenceTaxConfirmation();
    }

    public function updatedDifferenceTaxRate(): void
    {
        $this->resetDifferenceTaxConfirmation();
    }

    public function updatedDifferenceTaxReason(): void
    {
        $this->resetDifferenceTaxConfirmation();
    }

    public function updatedTaxReason(): void
    {
        $this->resetDifferenceTaxConfirmation();
    }

    public function updatedBreakdown(): void
    {
        $this->resetDifferenceTaxConfirmation();
    }

    public function updatedDifferenceCategory(): void
    {
        $this->resetDifferenceTaxConfirmation();
        $this->differenceTaxRate = in_array($this->differenceCategory, ['delivery', 'discount', 'other_fees'], true) ? $this->rate : '';
    }

    private function resetDifferenceTaxConfirmation(): void
    {
        $this->confirmDifferenceTaxTreatment = false;
    }

    protected function authorizeAmountEdit(): void
    {
        Gate::authorize('purchases.update');
        Gate::authorize('purchases.view');
        if ($this->invoiceId !== null) {
            abort_unless(SupplierInvoice::findOrFail($this->invoiceId)->isEditable(), 403);
        }
    }

    public function addComponent(): void
    {
        $this->authorizeAmountEdit();
        if ($this->breakdown === []) {
            $this->breakdown[] = $this->simpleComponent();
        }
        $this->breakdown[] = ['kind' => 'fee', 'label' => '', 'net_ht' => '', 'rate' => '', 'tax' => '', 'tax_reason' => '', 'rounding_reason' => ''];
        $this->detailed = true;
    }

    public function removeComponent(int $index): void
    {
        $this->authorizeAmountEdit();
        unset($this->breakdown[$index]);
        $this->breakdown = array_values($this->breakdown);
        // Never silently discard the last ventilation or substitute another tax calculation.
    }

    private function simpleComponent(): array
    {
        return ['kind' => 'purchase', 'label' => __('Achats'), 'net_ht' => $this->netHt, 'rate' => $this->rate,
            'tax' => $this->tax, 'tax_reason' => $this->taxReason, 'rounding_reason' => $this->roundingReason];
    }

    protected function supplierData(): array
    {
        $this->updateCalculatedTtc();

        return ['net_ht' => $this->netHt, 'tax_total' => $this->tax, 'total_ttc' => $this->documentTtc,
            'attachment_id' => $this->attachmentId, 'difference_reason' => $this->resolvedDifferenceReason(),
            'breakdown' => $this->detailed ? $this->breakdown : $this->preparedBreakdown()];
    }

    protected function amountPresentation(array $erp): array
    {
        $computedTtc = $difference = $differenceHt = $differenceTax = null;
        $hasDifference = $hasRounding = false;
        $fiscalIssue = null;
        $entered = $this->netHt !== '' || $this->tax !== '';
        try {
            $net = BigDecimal::of($this->netHt)->toScale(2);
            $tax = BigDecimal::of($this->tax)->toScale(2);
            $computedTtc = (string) $net->plus($tax);
            $differenceHt = (string) $net->minus($erp['base_ht'])->toScale(2);
            $differenceTax = (string) $tax->minus($erp['tax_total'])->toScale(2);
            $difference = (string) BigDecimal::of($computedTtc)->minus($erp['total_ttc'])->toScale(2);
            $hasDifference = ! BigDecimal::of($differenceHt)->isZero() || ! BigDecimal::of($differenceTax)->isZero();
            $this->documentTtc = $computedTtc;
            if ($this->tax !== '') {
                try {
                    $preview = app(SupplierInvoiceReconciliationCalculator::class)->calculate([...$this->supplierData(), 'attachment_id' => 1]);
                    $hasRounding = collect($preview['breakdown'])->contains(fn ($line) => ! BigDecimal::of($line['rounding_difference'])->isZero());
                } catch (ValidationException $exception) {
                    $fiscalIssue ??= $exception->errors()['reconciliation'][0] ?? __('Complétez les montants et la ventilation fiscale pour poursuivre.');
                }
            }
        } catch (\Throwable) {
            if ($entered) {
                $fiscalIssue = __('Vérifiez les montants HT et TVA saisis.');
            }
        }
        $advancedNeeded = $this->detailed || $fiscalIssue !== null || ($entered && ($this->rate === '' || $this->zeroTaxRate($this->rate)));
        $categoryRequired = $hasDifference && $this->differenceCategory === '';
        $differenceTreatmentRequired = $hasDifference && in_array($this->differenceCategory, ['delivery', 'discount', 'other_fees'], true)
            && ! $this->confirmDifferenceTaxTreatment;
        $reasonRequired = ($hasDifference || $hasRounding) && trim((string) $this->resolvedDifferenceReason()) === '';

        return compact('computedTtc', 'difference', 'differenceHt', 'differenceTax', 'hasDifference', 'hasRounding', 'fiscalIssue', 'advancedNeeded', 'entered', 'categoryRequired', 'differenceTreatmentRequired', 'reasonRequired');
    }

    private function updateCalculatedTtc(): void
    {
        try {
            if ($this->netHt === '' || $this->tax === '') {
                $this->documentTtc = '';

                return;
            }
            $this->documentTtc = (string) BigDecimal::of($this->netHt)->plus($this->tax)->toScale(2, RoundingMode::HALF_UP);
        } catch (\Throwable) {
            $this->documentTtc = '';
        }
    }

    private function resolvedDifferenceReason(): ?string
    {
        $category = match ($this->differenceCategory) {
            'price' => __('Différence de prix'),
            'delivery' => __('Frais de livraison'),
            'discount' => __('Remise'),
            'other_fees' => __('Autres frais'),
            'other' => __('Autre motif'),
            default => '',
        };

        if ($category === '') {
            return $this->differenceReason;
        }

        return trim($category.($this->differenceReason !== '' ? ' — '.$this->differenceReason : ''));
    }

    private function reasonCategory(string $reason): string
    {
        $categories = [
            'price' => 'Différence de prix',
            'delivery' => 'Frais de livraison',
            'discount' => 'Remise',
            'other_fees' => 'Autres frais',
            'other' => 'Autre motif',
        ];

        foreach ($categories as $category => $key) {
            foreach (['fr', 'en', 'ar'] as $locale) {
                $label = __($key, [], $locale);
                if ($reason === $label || str_starts_with($reason, $label.' — ')) {
                    return $category;
                }
            }
        }

        return '';
    }

    private function preparedBreakdown(): array
    {
        if (! in_array($this->differenceCategory, ['delivery', 'discount', 'other_fees'], true)) {
            return [$this->simpleComponent()];
        }

        $erp = $this->currentErpTotals();
        $rate = $this->differenceTaxRate !== '' ? $this->differenceTaxRate : $this->rate;
        if ($this->rate === '' || $rate === '' || ! is_numeric($this->rate) || ! is_numeric($rate)) {
            throw ValidationException::withMessages(['reconciliation' => __('Précisez le taux de TVA applicable dans les détails des taxes et frais.')]);
        }
        $netDifference = BigDecimal::of($this->netHt)->minus($erp['base_ht'])->toScale(2);
        $taxDifference = BigDecimal::of($this->tax)->minus($erp['tax_total'])->toScale(2);
        $adjustmentKind = $this->differenceCategory === 'delivery'
            ? 'transport'
            : ($this->differenceCategory === 'discount' ? 'discount' : 'fee');

        if ($adjustmentKind === 'discount') {
            $netDifference = $netDifference->negated();
            $taxDifference = $taxDifference->negated();
        }

        if ($netDifference->isLessThanOrEqualTo(0) || $taxDifference->isLessThan(0)) {
            throw ValidationException::withMessages(['reconciliation' => __('Le motif choisi ne correspond pas au sens de la différence. Vérifiez les détails des taxes et frais.')]);
        }

        $erpRate = $this->rate;
        $expectedErpTax = BigDecimal::of($erp['base_ht'])->multipliedBy($erpRate)->dividedBy(100, 2, RoundingMode::HALF_UP);
        $expectedAdjustmentTax = $netDifference->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);
        if (! $expectedErpTax->isEqualTo($erp['tax_total']) || ! $expectedAdjustmentTax->isEqualTo($taxDifference)) {
            throw ValidationException::withMessages(['reconciliation' => __('Les montants ne peuvent pas être ventilés automatiquement avec un seul taux. Ouvrez les détails des taxes et frais pour préciser les taux applicables.')]);
        }

        $label = match ($adjustmentKind) {
            'transport' => __('Frais de livraison'),
            'discount' => __('Remise'),
            default => __('Autres frais'),
        };

        return [
            ['kind' => 'purchase', 'label' => __('Achats'), 'net_ht' => $erp['base_ht'], 'rate' => $erpRate, 'tax' => $erp['tax_total'], 'tax_reason' => $this->taxReason, 'rounding_reason' => $this->roundingReason],
            ['kind' => $adjustmentKind, 'label' => $label, 'net_ht' => (string) $netDifference, 'rate' => $rate, 'tax' => (string) $taxDifference, 'tax_reason' => $this->differenceTaxReason, 'rounding_reason' => ''],
        ];
    }

    private function currentErpTotals(): array
    {
        $calculator = app(QuoteCalculator::class);
        if (! property_exists($this, 'lines')) {
            $invoice = SupplierInvoice::findOrFail($this->invoiceId);

            return [
                'subtotal_ht' => $invoice->subtotal_ht,
                'discount_total' => $invoice->discount_total,
                'base_ht' => $invoice->baseHt(),
                'tax_total' => $invoice->tax_total,
                'total_ttc' => $invoice->total_ttc,
            ];
        }

        $calculated = [];
        foreach (($this->lines ?? []) as $line) {
            if (! ($line['selected'] ?? false)) {
                continue;
            }
            $calculated[] = $calculator->line(
                (string) $line['quantity'],
                (string) $line['unit_price'],
                (string) $line['discount_percent'],
                (string) $line['tax_rate_percent'],
            );
        }

        return $calculator->totals($calculated);
    }

    public function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            return str_replace('.', app()->getLocale() === 'en' ? '.' : ',', (string) BigDecimal::of((string) $value)->toScale(2));
        } catch (\Throwable) {
            return '—';
        }
    }

    public function zeroTaxRate(string $rate): bool
    {
        try {
            return BigDecimal::of($rate)->isZero();
        } catch (\Throwable) {
            return false;
        }
    }
}
