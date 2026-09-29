<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\TaxRate;
use App\Services\QuoteCalculator;
use App\Services\QuoteManagementService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public ?int $quoteId = null;

    public string $customerId = '';
    public string $quoteDate = '';
    public string $validUntil = '';
    public string $terms = '';
    public string $notes = '';

    /** @var array<int, array{product_id: string, description: string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id: string}> */
    public array $lines = [];

    public function mount(?int $quoteId = null): void
    {
        $this->quoteDate = today()->toDateString();
        if ($quoteId === null) {
            Gate::authorize('sales.create');
            $this->addLine();

            return;
        }

        Gate::authorize('sales.update');
        $quote = Quote::query()->with('items')->findOrFail($quoteId);
        abort_unless($quote->isEditable(), 403);
        $this->quoteId = $quote->id;
        $this->customerId = (string) $quote->customer_id;
        $this->quoteDate = $quote->quote_date->toDateString();
        $this->validUntil = $quote->valid_until?->toDateString() ?? '';
        $this->terms = $quote->terms ?? '';
        $this->notes = $quote->notes ?? '';

        foreach ($quote->items as $item) {
            $this->lines[] = [
                'product_id' => (string) ($item->product_id ?? ''),
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_percent' => $item->discount_percent,
                'tax_rate_id' => (string) ($item->tax_rate_id ?? ''),
            ];
        }
    }

    public function updatedLines(mixed $value, string $key): void
    {
        if (! str_ends_with($key, '.product_id')) {
            return;
        }

        $index = (int) strtok($key, '.');
        $product = Product::query()->with('taxRate')->where('is_active', true)->find($value);
        if ($product === null || ! isset($this->lines[$index])) {
            return;
        }

        $this->lines[$index]['unit_price'] = $product->selling_price;
        $this->lines[$index]['tax_rate_id'] = (string) ($product->tax_rate_id ?? TaxRate::query()->where('is_active', true)->where('is_default', true)->value('id') ?? '');
    }

    public function addLine(): void
    {
        $this->authorizeMutation();
        $this->lines[] = ['product_id' => '', 'description' => '', 'quantity' => '1.000', 'unit_price' => '0.00', 'discount_percent' => '0.00', 'tax_rate_id' => ''];
    }

    public function removeLine(int $index): void
    {
        $this->authorizeMutation();
        if (array_key_exists($index, $this->lines)) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }
    }

    public function save(QuoteManagementService $service): void
    {
        $this->authorizeMutation();
        $attributes = [
            'customer_id' => $this->customerId,
            'quote_date' => $this->quoteDate,
            'valid_until' => $this->validUntil !== '' ? $this->validUntil : null,
            'terms' => $this->terms,
            'notes' => $this->notes,
        ];
        $quote = $this->quoteId === null
            ? $service->create($attributes, $this->lines)
            : $service->update(Quote::query()->findOrFail($this->quoteId), $attributes, $this->lines);

        $this->redirectRoute('sales.quotes.show', ['quote' => $quote], navigate: true);
    }

    public function with(QuoteCalculator $calculator): array
    {
        $this->authorizeMutation();
        $customers = Customer::query()->where('status', 'active')
            ->when($this->quoteId !== null && $this->customerId !== '', fn ($query) => $query->orWhere('id', (int) $this->customerId))
            ->orderBy('name')->get(['id', 'name', 'code', 'status']);
        $products = Product::query()->with('unit')->where('is_active', true)->orderBy('name')->get();
        $taxRates = TaxRate::query()->where('is_active', true)->orderBy('rate')->get();
        $defaultTaxRate = $taxRates->firstWhere('is_default', true);
        $lineTotals = [];
        $validLines = [];

        foreach ($this->lines as $index => $line) {
            $product = $products->firstWhere('id', (int) ($line['product_id'] ?? 0));
            $selectedTax = $line['tax_rate_id'] ?? '';
            $rate = $selectedTax === 'none'
                ? null
                : ($selectedTax !== ''
                    ? $taxRates->firstWhere('id', (int) $selectedTax)
                    : $taxRates->firstWhere('id', $product?->tax_rate_id) ?? $defaultTaxRate);
            try {
                $lineTotals[$index] = $calculator->line(
                    (string) ($line['quantity'] ?? ''),
                    (string) ($line['unit_price'] ?? ''),
                    (string) ($line['discount_percent'] ?? ''),
                    $rate?->rate ?? '0.00',
                );
                $validLines[] = $lineTotals[$index];
            } catch (\Throwable) {
                $lineTotals[$index] = null;
            }
        }

        return [
            'customers' => $customers,
            'products' => $products,
            'taxRates' => $taxRates,
            'lineTotals' => $lineTotals,
            'previewTotals' => $calculator->totals($validLines),
        ];
    }

    private function authorizeMutation(): void
    {
        Gate::authorize($this->quoteId === null ? 'sales.create' : 'sales.update');
        if ($this->quoteId !== null) {
            abort_unless(Quote::query()->findOrFail($this->quoteId)->isEditable(), 403);
        }
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end"><a href="{{ route('sales.quotes.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour aux devis') }}</a></div>
    <form wire:submit="save" class="space-y-6">
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Informations du devis') }}</h3>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2"><x-input-label for="quote-customer-id" :value="__('Client actif')" /><select id="quote-customer-id" wire:model="customerId" required class="mt-1 block w-full border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="">{{ __('Sélectionner un client') }}</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->code }} · {{ $customer->name }}{{ $customer->status === 'active' ? '' : ' · '.__('archivé') }}</option>@endforeach</select><x-input-error :messages="$errors->get('customer_id')" class="mt-2" /></div>
                <div><x-input-label for="quote-date" :value="__('Date du devis')" /><x-text-input id="quote-date" type="date" wire:model="quoteDate" required class="mt-1 block w-full" /><x-input-error :messages="$errors->get('quote_date')" class="mt-2" /></div>
                <div><x-input-label for="quote-valid-until" :value="__('Valide jusqu’au')" /><x-text-input id="quote-valid-until" type="date" wire:model="validUntil" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('valid_until')" class="mt-2" /></div>
                <div class="sm:col-span-2"><x-input-label for="quote-terms" :value="__('Conditions')" /><textarea id="quote-terms" wire:model="terms" rows="3" class="mt-1 block w-full border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea><x-input-error :messages="$errors->get('terms')" class="mt-2" /></div>
                <div class="sm:col-span-2"><x-input-label for="quote-notes" :value="__('Notes')" /><textarea id="quote-notes" wire:model="notes" rows="3" class="mt-1 block w-full border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
            </div>
        </section>

        <section class="border-y border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 sm:px-6"><h3 class="text-base font-semibold text-gray-900">{{ __('Lignes du devis') }}</h3><x-secondary-button type="button" wire:click="addLine">{{ __('Ajouter une ligne') }}</x-secondary-button></div>
            <x-input-error :messages="$errors->get('lines')" class="mx-5 mt-3 sm:mx-6" />
            <div class="divide-y divide-gray-200">
                @foreach ($lines as $index => $line)
                    <div wire:key="quote-line-{{ $index }}" class="grid gap-4 p-5 sm:p-6 lg:grid-cols-12">
                        <div class="lg:col-span-3"><x-input-label :for="'quote-product-'.$index" :value="__('Produit ou service')" /><select id="quote-product-{{ $index }}" wire:model.live="lines.{{ $index }}.product_id" required class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('Choisir un article actif') }}</option>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->reference }} · {{ $product->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('lines.'.$index.'.product_id')" class="mt-1" /></div>
                        <div class="lg:col-span-3"><x-input-label :for="'quote-description-'.$index" :value="__('Désignation (snapshot)')" /><x-text-input id="quote-description-{{ $index }}" wire:model="lines.{{ $index }}.description" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('lines.'.$index.'.description')" class="mt-1" /></div>
                        <div class="grid grid-cols-2 gap-3 lg:col-span-4">
                            <div><x-input-label :for="'quote-quantity-'.$index" :value="__('Quantité')" /><x-text-input id="quote-quantity-{{ $index }}" type="number" min="0.001" step="0.001" wire:model.live="lines.{{ $index }}.quantity" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('lines.'.$index.'.quantity')" class="mt-1" /></div>
                            <div><x-input-label :for="'quote-unit-price-'.$index" :value="__('PU HT')" /><x-text-input id="quote-unit-price-{{ $index }}" type="number" min="0" step="0.01" wire:model.live="lines.{{ $index }}.unit_price" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('lines.'.$index.'.unit_price')" class="mt-1" /></div>
                            <div><x-input-label :for="'quote-discount-'.$index" :value="__('Remise %')" /><x-text-input id="quote-discount-{{ $index }}" type="number" min="0" max="100" step="0.01" wire:model.live="lines.{{ $index }}.discount_percent" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('lines.'.$index.'.discount_percent')" class="mt-1" /></div>
                            <div><x-input-label :for="'quote-tax-'.$index" :value="__('TVA')" /><select id="quote-tax-{{ $index }}" wire:model.live="lines.{{ $index }}.tax_rate_id" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('Automatique') }}</option><option value="none">{{ __('0 %') }}</option>@foreach ($taxRates as $taxRate)<option value="{{ $taxRate->id }}">{{ $taxRate->label }} · {{ str_replace('.', ',', $taxRate->rate) }} %</option>@endforeach</select></div>
                        </div>
                        <div class="flex items-end justify-between gap-3 lg:col-span-2 lg:flex-col lg:items-end lg:justify-center"><div class="text-right"><span class="block text-xs text-gray-500">{{ __('Total ligne TTC') }}</span><strong class="text-sm text-gray-900">{{ $lineTotals[$index]['total_ttc'] ?? '—' }}</strong></div><button type="button" wire:click="removeLine({{ $index }})" class="text-sm font-medium text-rose-700 hover:text-rose-900">{{ __('Supprimer') }}</button></div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Synthèse') }}</h3>
            <dl class="mt-4 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-5">
                <div><dt class="text-gray-500">{{ __('Brut HT') }}</dt><dd class="font-medium text-gray-900">{{ str_replace('.', ',', $previewTotals['subtotal_ht']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Remises') }}</dt><dd class="font-medium text-gray-900">{{ str_replace('.', ',', $previewTotals['discount_total']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('HT net') }}</dt><dd class="font-medium text-gray-900">{{ str_replace('.', ',', $previewTotals['base_ht']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('TVA') }}</dt><dd class="font-medium text-gray-900">{{ str_replace('.', ',', $previewTotals['tax_total']) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('TTC') }}</dt><dd class="text-lg font-semibold text-gray-950">{{ str_replace('.', ',', $previewTotals['total_ttc']) }}</dd></div>
            </dl>
        </section>
        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="{{ route('sales.quotes.index') }}" wire:navigate class="inline-flex min-h-10 items-center justify-center border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Annuler') }}</a><x-primary-button type="submit" class="justify-center">{{ __('Enregistrer le brouillon') }}</x-primary-button></div>
    </form>
</section>