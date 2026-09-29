<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

class QuoteCalculator
{
    /**
     * Every monetary step is rounded to two decimals with HALF_UP.
     *
     * @return array{quantity: string, unit_price: string, discount_percent: string, tax_rate_percent: string, gross_ht: string, discount_amount: string, subtotal_ht: string, tax_amount: string, total_ttc: string}
     */
    public function line(string|int $quantity, string|int $unitPrice, string|int $discountPercent, string|int $taxRatePercent): array
    {
        try {
            $quantityDecimal = BigDecimal::of($quantity)->toScale(3, RoundingMode::UNNECESSARY);
            $unitPriceDecimal = BigDecimal::of($unitPrice)->toScale(2, RoundingMode::UNNECESSARY);
            $discountDecimal = BigDecimal::of($discountPercent)->toScale(2, RoundingMode::UNNECESSARY);
            $taxDecimal = BigDecimal::of($taxRatePercent)->toScale(2, RoundingMode::UNNECESSARY);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(__('Une valeur décimale est invalide.'), previous: $exception);
        }

        if ($quantityDecimal->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException(__('La quantité doit être supérieure à zéro.'));
        }

        if ($unitPriceDecimal->isLessThan(0)) {
            throw new InvalidArgumentException(__('Le prix unitaire ne peut pas être négatif.'));
        }

        if ($discountDecimal->isLessThan(0) || $discountDecimal->isGreaterThan(100)) {
            throw new InvalidArgumentException(__('La remise doit être comprise entre 0 et 100 %.'));
        }

        if ($taxDecimal->isLessThan(0) || $taxDecimal->isGreaterThan(100)) {
            throw new InvalidArgumentException(__('Le taux de TVA doit être compris entre 0 et 100 %.'));
        }

        $gross = $quantityDecimal->multipliedBy($unitPriceDecimal)->toScale(2, RoundingMode::HALF_UP);
        $discount = $gross->multipliedBy($discountDecimal)->dividedBy(100, 2, RoundingMode::HALF_UP);
        $net = $gross->minus($discount)->toScale(2);
        $tax = $net->multipliedBy($taxDecimal)->dividedBy(100, 2, RoundingMode::HALF_UP);
        $total = $net->plus($tax)->toScale(2);

        return [
            'quantity' => (string) $quantityDecimal,
            'unit_price' => (string) $unitPriceDecimal,
            'discount_percent' => (string) $discountDecimal,
            'tax_rate_percent' => (string) $taxDecimal,
            'gross_ht' => (string) $gross,
            'discount_amount' => (string) $discount,
            'subtotal_ht' => (string) $net,
            'tax_amount' => (string) $tax,
            'total_ttc' => (string) $total,
        ];
    }

    /**
     * @param  array<int, array{gross_ht: string, discount_amount: string, subtotal_ht: string, tax_amount: string, total_ttc: string}>  $lines
     * @return array{subtotal_ht: string, discount_total: string, base_ht: string, tax_total: string, total_ttc: string}
     */
    public function totals(array $lines): array
    {
        $gross = BigDecimal::zero()->toScale(2);
        $discount = BigDecimal::zero()->toScale(2);
        $base = BigDecimal::zero()->toScale(2);
        $tax = BigDecimal::zero()->toScale(2);
        $total = BigDecimal::zero()->toScale(2);

        foreach ($lines as $line) {
            $gross = $gross->plus($line['gross_ht']);
            $discount = $discount->plus($line['discount_amount']);
            $base = $base->plus($line['subtotal_ht']);
            $tax = $tax->plus($line['tax_amount']);
            $total = $total->plus($line['total_ttc']);
        }

        return [
            'subtotal_ht' => (string) $gross,
            'discount_total' => (string) $discount,
            'base_ht' => (string) $base,
            'tax_total' => (string) $tax,
            'total_ttc' => (string) $total,
        ];
    }
}
