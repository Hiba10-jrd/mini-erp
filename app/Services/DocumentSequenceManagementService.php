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
        Gate::authorize('sales.create');

        return DB::transaction(function () use ($documentType, $year): string {
            $sequence = DocumentSequence::query()
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $document = match ($documentType) {
                    'quote' => __('des devis'),
                    'order' => __('des commandes clients'),
                    default => __('demandée'),
                };
                $field = match ($documentType) {
                    'quote' => 'quoteDate',
                    'order' => 'orderDate',
                    default => 'document',
                };

                throw ValidationException::withMessages([
                    $field => __('La séquence documentaire :document n’est pas configurée pour :year.', ['document' => $document, 'year' => $year]),
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
            $attributes['prefix'],
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
}
