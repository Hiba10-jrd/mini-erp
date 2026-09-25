<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DocumentSequenceManagementService
{
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

        $format = $attributes['number_format'];
        $counter = (string) ($attributes['counter'] + 1);

        if (preg_match('/\{counter:0*([1-9][0-9]*)d\}/', $format, $matches) === 1) {
            $counter = str_pad($counter, (int) $matches[1], '0', STR_PAD_LEFT);
            $format = preg_replace('/\{counter:0*[1-9][0-9]*d\}/', '{counter}', $format) ?? $format;
        }

        return strtr($format, [
            '{prefix}' => $attributes['prefix'],
            '{year}' => (string) $attributes['year'],
            '{counter}' => $counter,
        ]);
    }
}
