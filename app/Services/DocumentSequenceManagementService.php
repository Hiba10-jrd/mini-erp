<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentSequenceManagementService
{
    public function allocate(string $documentType, int $year): string
    {
        Gate::authorize(match ($documentType) {
            'invoice', 'credit_note' => 'invoices.validate',
            'purchase_order' => 'purchases.create',
            default => 'sales.create',
        });

        return DB::transaction(function () use ($documentType, $year): string {
            $documentType = $this->normalizeDocumentType($documentType);
            $sequence = DocumentSequence::query()
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $document = match ($documentType) {
                    'quote' => __('des devis'),
                    'order' => __('des commandes clients'),
                    'delivery_note' => __('des bons de livraison'),
                    'purchase_order' => __('des commandes fournisseurs'),
                    default => __('demandée'),
                };
                $field = match ($documentType) {
                    'quote' => 'quoteDate',
                    'order' => 'orderDate',
                    'delivery_note' => 'deliveryNoteDate',
                    'purchase_order' => 'order_date',
                    default => 'document',
                };

                throw ValidationException::withMessages([
                    $field => __('La séquence documentaire :document n’est pas configurée pour :year.', ['document' => $document, 'year' => $year]),
                ]);
            }

            if (! $this->prefixMatchesDocumentType($documentType, $sequence->prefix)) {
                throw ValidationException::withMessages([
                    'document_type' => __('La séquence :document doit utiliser le préfixe :expected pour rester cohérente avec le type documentaire.', [
                        'document' => $documentType,
                        'expected' => $this->expectedPrefix($documentType),
                    ]),
                ]);
            }

            $sequence->counter++;
            $sequence->save();

            return $this->format($sequence->number_format, $sequence->prefix, $sequence->year, $sequence->counter);
        });
    }

    /** @param array{document_type: string, prefix: string, year: int, counter: int, number_format: string} $attributes */
    public function save(?int $sequenceId, array $attributes): DocumentSequence
    {
        Gate::authorize('company.administer');

        return DB::transaction(function () use ($sequenceId, $attributes): DocumentSequence {
            $documentType = $this->normalizeDocumentType($attributes['document_type'] ?? 'quote');
            $prefix = strtoupper((string) ($attributes['prefix'] ?? $this->expectedPrefix($documentType)));

            $attributes['document_type'] = $documentType;
            $attributes['prefix'] = $prefix;

            $sequence = $sequenceId === null
                ? new DocumentSequence
                : DocumentSequence::query()->lockForUpdate()->findOrFail($sequenceId);

            $sequence->fill($attributes)->save();

            return $sequence->fresh();
        });
    }

    /** @param array{prefix: string, year: int, counter: int, number_format: string} $attributes */
    public function preview(array $attributes): string
    {
        Gate::authorize('company.administer');

        return $this->format(
            $attributes['number_format'],
            strtoupper((string) ($attributes['prefix'] ?? $this->expectedPrefix($this->normalizeDocumentType($attributes['document_type'] ?? 'quote')))),
            $attributes['year'],
            $attributes['counter'] + 1,
        );
    }

    private function format(string $format, string $prefix, int $year, int $counterValue): string
    {
        $counter = (string) $counterValue;

        if (preg_match('/\{counter:0*([1-9][0-9]*)d\}/', $format, $matches) === 1) {
            $counter = str_pad($counter, (int) $matches[1], '0', STR_PAD_LEFT);
            $format = preg_replace('/\{counter:0*[1-9][0-9]*d\}/', '{counter}', $format) ?? $format;
        }

        return strtr($format, [
            '{prefix}' => $prefix,
            '{year}' => (string) $year,
            '{counter}' => $counter,
        ]);
    }

    private function normalizeDocumentType(string $documentType): string
    {
        return match ($documentType) {
            'quote', 'order', 'delivery_note', 'invoice', 'credit_note', 'purchase_order' => $documentType,
            default => 'quote',
        };
    }

    private function expectedPrefix(string $documentType): string
    {
        return match ($documentType) {
            'quote' => 'DEV',
            'order' => 'CMD',
            'delivery_note' => 'BL',
            'invoice' => 'FAC',
            'credit_note' => 'AV',
            'purchase_order' => 'BCF',
            default => 'DEV',
        };
    }

    private function prefixMatchesDocumentType(string $documentType, string $prefix): bool
    {
        $prefix = strtoupper((string) $prefix);
        $expected = $this->expectedPrefix($documentType);

        return $prefix === $expected || str_starts_with($prefix, $expected.'-');
    }
}
