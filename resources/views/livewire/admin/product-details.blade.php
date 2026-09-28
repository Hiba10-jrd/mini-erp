<?php

use App\Models\Product;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $productId;

    public function mount(int $productId): void
    {
        Gate::authorize('products.access');
        $this->productId = $productId;
        Product::query()->findOrFail($productId);
    }

    public function with(): array
    {
        Gate::authorize('products.access');
        $product = Product::query()->with(['category', 'unit', 'taxRate'])->findOrFail($this->productId);

        return [
            'product' => $product,
            'imageUrl' => $product->image_path ? Storage::disk('public')->url($product->image_path) : null,
        ];
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end">
        <a href="{{ route('admin.products.index') }}" wire:navigate class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('Retour au catalogue') }}</a>
    </div>

    <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="grid gap-6 p-6 sm:grid-cols-[minmax(0,1fr)_14rem]">
            <div>
                <div class="flex flex-col gap-3 border-b border-gray-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-indigo-600">{{ $product->reference }}</p>
                        <h3 class="text-xl font-semibold text-gray-900">{{ $product->name }}</h3>
                        <p class="text-sm text-gray-500">{{ $product->isService() ? __('Service') : __('Produit physique') }}@if ($product->brand) · {{ $product->brand }} @endif</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-sm {{ $product->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-700' }}">
                        {{ $product->is_active ? __('Actif') : __('Inactif') }}
                    </span>
                </div>

                <dl class="mt-5 grid gap-4 text-sm text-gray-600 sm:grid-cols-2">
                    <div><dt class="font-medium text-gray-900">{{ __('Code-barres') }}</dt><dd>{{ $product->barcode ?? __('Non renseigné') }}</dd></div>
                    <div><dt class="font-medium text-gray-900">{{ __('Catégorie') }}</dt><dd>{{ $product->category?->name ?? __('Non classé') }}</dd></div>
                    <div><dt class="font-medium text-gray-900">{{ __('Unité') }}</dt><dd>{{ $product->unit->name }} ({{ $product->unit->symbol }})</dd></div>
                    <div><dt class="font-medium text-gray-900">{{ __('TVA') }}</dt><dd>{{ $product->taxRate ? $product->taxRate->label.' — '.number_format((float) $product->taxRate->rate, 2, ',', ' ').' %' : __('Aucune TVA sélectionnée') }}</dd></div>
                    <div><dt class="font-medium text-gray-900">{{ __('Prix d’achat') }}</dt><dd>{{ number_format((float) $product->purchase_price, 2, ',', ' ') }}</dd></div>
                    <div><dt class="font-medium text-gray-900">{{ __('Prix de vente') }}</dt><dd>{{ number_format((float) $product->selling_price, 2, ',', ' ') }}</dd></div>
                </dl>
            </div>

            <div class="flex min-h-44 items-center justify-center rounded-lg border border-gray-200 bg-gray-50 p-3">
                @if ($imageUrl)
                    <img src="{{ $imageUrl }}" alt="{{ $product->name }}" class="max-h-52 max-w-full object-contain" />
                @else
                    <span class="text-center text-xs text-gray-500">{{ __('Aucune photo enregistrée') }}</span>
                @endif
            </div>
        </div>

        @if ($product->description)
            <div class="border-t border-gray-200 px-6 py-5">
                <h4 class="font-medium text-gray-900">{{ __('Description') }}</h4>
                <p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $product->description }}</p>
            </div>
        @endif
    </div>

    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
        @if ($product->isService())
            <h4 class="font-medium text-gray-900">{{ __('Prestation de service') }}</h4>
            <p class="mt-2 text-sm text-gray-600">{{ __('Cette prestation ne possède aucun seuil ni mouvement de stock.') }}</p>
        @else
            <h4 class="font-medium text-gray-900">{{ __('Paramètres de stock préparatoires') }}</h4>
            <dl class="mt-3 grid gap-3 text-sm text-gray-600 sm:grid-cols-2">
                <div><dt class="font-medium">{{ __('Minimum') }}</dt><dd>{{ $product->minimum_stock ?? __('Non défini') }}</dd></div>
                <div><dt class="font-medium">{{ __('Maximum') }}</dt><dd>{{ $product->maximum_stock ?? __('Non défini') }}</dd></div>
            </dl>
            <p class="mt-4 text-xs text-gray-500">{{ __('Le stock réel sera disponible après activation du module Stocks et dépôts.') }}</p>
        @endif
    </div>
</section>
