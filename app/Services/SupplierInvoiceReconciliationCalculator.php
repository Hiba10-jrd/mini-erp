<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceReconciliationCalculator
{
    /** Amounts are document values; each component has an explicit tax treatment. */
    public function calculate(array $data): array
    {
        $data = Validator::make($data, [
            'net_ht' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'tax_total' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'total_ttc' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'attachment_id' => ['required', 'integer'],
            'difference_reason' => ['nullable', 'string', 'max:5000'],
            'breakdown' => ['required', 'array', 'min:1', 'max:100'],
            'breakdown.*.kind' => ['required', 'in:purchase,discount,transport,fee'],
            'breakdown.*.label' => ['required', 'string', 'max:200'],
            'breakdown.*.net_ht' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'breakdown.*.rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'breakdown.*.tax' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'breakdown.*.tax_reason' => ['nullable', 'string', 'max:500'],
            'breakdown.*.rounding_reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $net = BigDecimal::zero();
        $tax = BigDecimal::zero();
        $lines = [];
        foreach ($data['breakdown'] as $index => $line) {
            $base = BigDecimal::of($line['net_ht'])->toScale(2);
            $rate = BigDecimal::of($line['rate'])->toScale(2);
            $declaredTax = BigDecimal::of($line['tax'])->toScale(2);
            $expectedTax = $base->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);
            $rounding = $declaredTax->minus($expectedTax);
            if ($rate->isZero() && trim($line['tax_reason'] ?? '') === '') {
                throw ValidationException::withMessages(['reconciliation' => __('Précisez le traitement fiscal des montants sans TVA.')]);
            }
            // Only a documented one-cent rounding difference is supported, never an arbitrary tax override.
            if ($rounding->abs()->isGreaterThan('0.01') || (! $rounding->isZero() && trim($line['rounding_reason'] ?? '') === '')) {
                throw ValidationException::withMessages(['reconciliation' => __('La TVA déclarée ne correspond pas à la ventilation fiscale.')]);
            }
            $sign = $line['kind'] === 'discount' ? -1 : 1;
            $net = $net->plus($base->multipliedBy($sign));
            $tax = $tax->plus($declaredTax->multipliedBy($sign));
            $lines[] = [
                'kind' => $line['kind'], 'label' => $line['label'], 'net_ht' => (string) $base,
                'rate' => (string) $rate, 'tax' => (string) $declaredTax,
                'calculated_tax' => (string) $expectedTax, 'rounding_difference' => (string) $rounding,
                'tax_reason' => trim($line['tax_reason'] ?? ''), 'rounding_reason' => trim($line['rounding_reason'] ?? ''),
            ];
        }
        $total = $net->plus($tax);
        if ($net->isLessThan(0) || $tax->isLessThan(0)
            || ! $net->isEqualTo($data['net_ht']) || ! $tax->isEqualTo($data['tax_total']) || ! $total->isEqualTo($data['total_ttc'])) {
            throw ValidationException::withMessages(['reconciliation' => __('Les montants du document doivent correspondre à la ventilation HT et TVA.')]);
        }

        return [
            'net_ht' => (string) $net->toScale(2), 'tax_total' => (string) $tax->toScale(2),
            'total_ttc' => (string) $total->toScale(2), 'breakdown' => $lines,
            'attachment_id' => $data['attachment_id'], 'difference_reason' => trim($data['difference_reason'] ?? '') ?: null,
        ];
    }
}
