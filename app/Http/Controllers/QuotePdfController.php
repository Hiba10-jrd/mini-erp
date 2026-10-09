<?php

namespace App\Http\Controllers;

use App\Models\CommercialSetting;
use App\Models\Company;
use App\Models\Quote;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class QuotePdfController
{
    public function __invoke(Quote $quote)
    {
        Gate::authorize('sales.view');
        $quote->load(['creator', 'items']);
        $company = Company::query()->where('singleton', true)->first();
        $logoData = null;

        if ($company?->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $mimeType = mime_content_type(Storage::disk('public')->path($company->logo_path)) ?: 'image/png';
            $logoData = 'data:'.$mimeType.';base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));
        }

        $pdfLocale = app()->getLocale() === 'en' ? 'en' : 'fr';
        app()->setLocale($pdfLocale);

        $viewData = [
            'quote' => $quote,
            'company' => $company,
            'logoData' => $logoData,
            'commercialSetting' => CommercialSetting::query()->where('singleton', true)->first(),
            'pdfLocale' => $pdfLocale,
        ];

        if (! class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            abort(503, __('Le moteur PDF n’est pas installé.'));
        }

        return app('dompdf.wrapper')->loadView('pdf.quote', $viewData)->setPaper('a4')->download($quote->number.'.pdf');
    }
}
