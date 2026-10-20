<?php

namespace Tests\Support;

use App\Models\SupplierInvoice;
use App\Services\SupplierInvoiceManagementService;
use Brick\Math\BigDecimal;

trait SubmitsSupplierInvoices
{
    private function matchingReconciliation(SupplierInvoice $invoice): array
    {
        $document = $invoice->attachments()->create([
            'original_name' => 'original.pdf', 'path' => 'isolated-test/'.str()->uuid().'.pdf',
            'mime_type' => 'application/pdf', 'size' => 100,
            'category' => SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY,
        ]);

        return [
            'net_ht' => $invoice->baseHt(), 'tax_total' => $invoice->tax_total, 'total_ttc' => $invoice->total_ttc,
            'attachment_id' => $document->id, 'difference_reason' => 'Arrondis du document vérifiés.',
            'breakdown' => $invoice->items->map(fn ($line) => [
                'kind' => 'purchase', 'label' => $line->description, 'net_ht' => $line->subtotal_ht,
                'rate' => $line->tax_rate_percent, 'tax' => $line->tax_amount,
                'tax_reason' => BigDecimal::of($line->tax_rate_percent)->isZero() ? 'Hors TVA, justificatif contrôlé.' : '',
                'rounding_reason' => 'Arrondi par ligne du document.',
            ])->all(),
        ];
    }

    private function submitMatchingInvoice(SupplierInvoice $invoice): SupplierInvoice
    {
        return app(SupplierInvoiceManagementService::class)->submitForReview($invoice, $this->matchingReconciliation($invoice));
    }
}
