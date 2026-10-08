<?php

namespace App\Http\Controllers;

use App\Models\CommercialSetting;
use App\Models\Company;
use App\Models\DeliveryNote;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DeliveryNotePdfController
{
    public function __invoke(DeliveryNote $deliveryNote)
    {
        Gate::authorize('sales.view');

        $deliveryNote->load(['salesOrder.customer', 'salesOrder.items', 'warehouse', 'creator', 'items']);

        $company = Company::query()->where('singleton', true)->first();
        $logoData = null;

        if ($company?->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $mimeType = mime_content_type(Storage::disk('public')->path($company->logo_path)) ?: 'image/png';
            $logoData = 'data:'.$mimeType.';base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));
        }

        $pdfLocale = app()->getLocale() === 'en' ? 'en' : 'fr';
        app()->setLocale($pdfLocale);

        $viewData = [
            'deliveryNote' => $deliveryNote,
            'company' => $company,
            'logoData' => $logoData,
            'commercialSetting' => CommercialSetting::query()->where('singleton', true)->first(),
            'pdfLocale' => $pdfLocale,
        ];

        if (! class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            abort(503, __('Le moteur PDF n’est pas installé.'));
        }

        return app('dompdf.wrapper')->loadView('pdf.delivery-note', $viewData)->setPaper('a4')->download($deliveryNote->number.'.pdf');
    }
}
