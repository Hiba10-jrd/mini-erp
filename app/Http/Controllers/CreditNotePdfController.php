<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CreditNote;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class CreditNotePdfController
{
    public function __invoke(CreditNote $creditNote)
    {
        Gate::authorize('invoices.view');

        abort_unless(
            $creditNote->isIssued(),
            404,
            __('L’avoir doit être émis avant de générer son PDF.')
        );

        $creditNote->load([
            'invoice',
            'creator:id,name,email',
            'issuer:id,name,email',
            'items',
        ]);

        $company = Company::query()
            ->where('singleton', true)
            ->first();

        $logoData = null;

        if (
            $company?->logo_path
            && Storage::disk('public')->exists($company->logo_path)
        ) {
            $path = Storage::disk('public')->path($company->logo_path);

            $mimeType = mime_content_type($path) ?: 'image/png';

            $logoData = 'data:'
                .$mimeType
                .';base64,'
                .base64_encode(
                    Storage::disk('public')->get($company->logo_path)
                );
        }

        if (! class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            abort(503, __('Le moteur PDF n’est pas installé.'));
        }

        $filename = str_replace(
            ['/', '\\'],
            '-',
            $creditNote->number
        ).'.pdf';

        return app('dompdf.wrapper')
            ->loadView('pdf.credit-note', [
                'creditNote' => $creditNote,
                'invoice' => $creditNote->invoice,
                'logoData' => $logoData,
            ])
            ->setPaper('a4')
            ->download($filename);
    }
}
