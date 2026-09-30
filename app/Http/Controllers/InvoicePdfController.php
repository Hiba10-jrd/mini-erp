<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class InvoicePdfController
{
    public function __invoke(Invoice $invoice)
    {
        Gate::authorize('invoices.view');

        abort_unless(
            $invoice->isIssued(),
            404,
            __('La facture doit être émise avant de générer son PDF.')
        );

        $invoice->load([
            'salesOrder:id,number',
            'creator:id,name,email',
            'issuer:id,name,email',
            'items',
        ]);

        /*
         * Les informations commerciales de la société et du client
         * proviennent des snapshots de la facture.
         *
         * La société courante est chargée uniquement pour récupérer
         * éventuellement son logo.
         */
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
            $invoice->number
        ).'.pdf';

        return app('dompdf.wrapper')
            ->loadView('pdf.invoice', [
                'invoice' => $invoice,
                'logoData' => $logoData,
            ])
            ->setPaper('a4')
            ->download($filename);
    }
}
