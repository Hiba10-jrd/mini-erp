<?php

use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Services\PurchaseOrderManagementService;
use App\Services\QuoteCalculator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public ?int $orderId = null;

    public string $supplierId = '';
    public string $paymentTermId = '';
    public string $orderDate = '';
    public string $expectedDate = '';
    public string $terms = '';
    public string $notes = '';

    /** @var array<int, array{product_id: string, description: string, quantity: string, unit_price: string, discount_percent: string, tax_rate_id: string}> */
    public array $lines = [];

    public function mount(?int $orderId = null): void
    {
        $this->orderDate = today()->toDateString();
        if ($orderId === null) {
            Gate::authorize('purchases.create');
            $this->addLine();

            return;
        }

        Gate::authorize('purchases.update');
        $order = PurchaseOrder::query()->with('items')->findOrFail($orderId);
        abort_unless($order->isEditable(), 403);
        $this->orderId = $order->id;
        $this->supplierId = (string) $order->supplier_id;
        $this->paymentTermId = (string) ($order->payment_term_id ?? '');
        $this->orderDate = $order->order_date->toDateString();
        $this->expectedDate = $order->expected_date?->toDateString() ?? '';
        $this->terms = $order->terms ?? '';
        $this->notes = $order->notes ?? '';
        foreach ($order->items as $item) {
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

    public function updatedSupplierId(mixed $value): void
    {
        $this->authorizeMutation();
        $supplier = Supplier::query()->where('status', 'active')->find($value);
        $this->paymentTermId = (string) ($supplier?->payment_term_id ?? '');
    }

    public function updatedLines(mixed $value, string $key): void
    {
        $this->authorizeMutation();
        if (! str_ends_with($key, '.product_id')) {
            return;
        }
        $index = (int) strtok($key, '.');
        $product = Product::query()->with('taxRate')->where('is_active', true)->find($value);
        if ($product === null || ! isset($this->lines[$index])) {
            return;
        }
        $this->lines[$index]['description'] = $product->name;
        $this->lines[$index]['unit_price'] = $product->purchase_price;
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

    public function save(PurchaseOrderManagementService $service): void
    {
        $this->authorizeMutation();
        $attributes = [
            'supplier_id' => $this->supplierId,
            'payment_term_id' => $this->paymentTermId !== '' ? $this->paymentTermId : null,
            'order_date' => $this->orderDate,
            'expected_date' => $this->expectedDate !== '' ? $this->expectedDate : null,
            'terms' => $this->terms,
            'notes' => $this->notes,
        ];
        $order = $this->orderId === null
            ? $service->create($attributes, $this->lines)
            : $service->updateDraft(PurchaseOrder::query()->findOrFail($this->orderId), $attributes, $this->lines);

        $this->redirectRoute('purchases.orders.show', ['purchaseOrder' => $order], navigate: true);
    }

    public function with(QuoteCalculator $calculator): array
    {
        $this->authorizeMutation();
        $suppliers = Supplier::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'payment_term_id']);
        $paymentTerms = PaymentTerm::query()->where('is_active', true)->orderBy('due_days')->orderBy('label')->get();
        $products = Product::query()->with('unit')->where('is_active', true)->orderBy('name')->get();
        $taxRates = TaxRate::query()->where('is_active', true)->orderBy('rate')->get();
        $defaultTaxRate = $taxRates->firstWhere('is_default', true);
        $lineTotals = [];
        $validLines = [];
        foreach ($this->lines as $index => $line) {
            $product = $products->firstWhere('id', (int) ($line['product_id'] ?? 0));
            $selectedTax = $line['tax_rate_id'] ?? '';
            $rate = $selectedTax === 'none' ? null : ($selectedTax !== '' ? $taxRates->firstWhere('id', (int) $selectedTax) : $taxRates->firstWhere('id', $product?->tax_rate_id) ?? $defaultTaxRate);
            try {
                $lineTotals[$index] = $calculator->line((string) ($line['quantity'] ?? ''), (string) ($line['unit_price'] ?? ''), (string) ($line['discount_percent'] ?? ''), $rate?->rate ?? '0.00');
                $validLines[] = $lineTotals[$index];
            } catch (\Throwable) {
                $lineTotals[$index] = null;
            }
        }

        return compact('suppliers', 'paymentTerms', 'products', 'taxRates', 'lineTotals') + ['previewTotals' => $calculator->totals($validLines)];
    }

    private function authorizeMutation(): void
    {
        Gate::authorize($this->orderId === null ? 'purchases.create' : 'purchases.update');
        if ($this->orderId !== null) {
            abort_unless(PurchaseOrder::query()->findOrFail($this->orderId)->isEditable(), 403);
        }
    }
}; ?>

<section class="space-y-6">
    <div class="flex justify-end"><a href="{{ route('purchases.orders.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Retour aux commandes fournisseurs') }}</a></div>
    <form wire:submit="save" class="space-y-6">
        <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900">{{ __('Informations de la commande') }}</h3>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2"><x-input-label for="purchase-supplier-id" :value="__('Fournisseur actif')" /><select id="purchase-supplier-id" wire:model.live="supplierId" required class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('Sélectionner un fournisseur') }}</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ $supplier->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('supplier_id')" class="mt-2" /></div>
                <div><x-input-label for="purchase-order-date" :value="__('Date de commande')" /><x-text-input id="purchase-order-date" type="date" wire:model="orderDate" required class="mt-1 block w-full" /><x-input-error :messages="$errors->get('order_date')" class="mt-2" /></div>
                <div><x-input-label for="purchase-expected-date" :value="__('Date prévue')" /><x-text-input id="purchase-expected-date" type="date" wire:model="expectedDate" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('expected_date')" class="mt-2" /></div>
                <div class="sm:col-span-2"><x-input-label for="purchase-payment-term" :value="__('Condition de paiement')" /><select id="purchase-payment-term" wire:model="paymentTermId" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('Aucune') }}</option>@foreach ($paymentTerms as $paymentTerm)<option value="{{ $paymentTerm->id }}">{{ $paymentTerm->label }}</option>@endforeach</select><x-input-error :messages="$errors->get('payment_term_id')" class="mt-2" /></div>
                <div class="sm:col-span-2"><x-input-label for="purchase-terms" :value="__('Conditions')" /><textarea id="purchase-terms" wire:model="terms" rows="3" class="mt-1 block w-full border-gray-300 shadow-sm"></textarea><x-input-error :messages="$errors->get('terms')" class="mt-2" /></div>
                <div class="sm:col-span-4"><x-input-label for="purchase-notes" :value="__('Notes')" /><textarea id="purchase-notes" wire:model="notes" rows="3" class="mt-1 block w-full border-gray-300 shadow-sm"></textarea><x-input-error :messages="$errors->get('notes')" class="mt-2" /></div>
            </div>
        </section>

        <section class="border-y border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-5 py-4 sm:px-6"><h3 class="text-base font-semibold text-gray-900">{{ __('Lignes de commande') }}</h3><x-secondary-button type="button" wire:click="addLine">{{ __('Ajouter une ligne') }}</x-secondary-button></div>
            <x-input-error :messages="$errors->get('lines')" class="mx-5 mt-3 sm:mx-6" />
            <div class="divide-y divide-gray-200">
                @foreach ($lines as $index => $line)
                    @php($selectedProduct = $products->firstWhere('id', (int) ($line['product_id'] ?? 0)))
                    <div wire:key="purchase-line-{{ $index }}" class="grid gap-4 p-5 sm:p-6 lg:grid-cols-12">
                        <div class="lg:col-span-3"><x-input-label :for="'purchase-product-'.$index" :value="__('Produit ou service')" /><select id="purchase-product-{{ $index }}" wire:model.live="lines.{{ $index }}.product_id" required class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('Choisir un article actif') }}</option>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->reference }} · {{ $product->name }}</option>@endforeach</select><x-input-error :messages="$errors->get('lines.'.$index.'.product_id')" class="mt-1" /></div>
                        <div class="lg:col-span-3"><x-input-label :for="'purchase-description-'.$index" :value="__('Désignation (snapshot)')" /><x-text-input id="purchase-description-{{ $index }}" wire:model="lines.{{ $index }}.description" class="mt-1 block w-full" /><p class="mt-1 text-xs text-gray-500">{{ __('Unité') }} : {{ $selectedProduct?->unit?->symbol ?? '—' }}</p></div>
                        <div class="grid grid-cols-2 gap-3 lg:col-span-4"><div><x-input-label :for="'purchase-quantity-'.$index" :value="__('Quantité')" /><x-text-input id="purchase-quantity-{{ $index }}" type="number" min="0.001" step="0.001" wire:model.live="lines.{{ $index }}.quantity" class="mt-1 block w-full" /></div><div><x-input-label :for="'purchase-price-'.$index" :value="__('PU achat HT')" /><x-text-input id="purchase-price-{{ $index }}" type="number" min="0" step="0.01" wire:model.live="lines.{{ $index }}.unit_price" class="mt-1 block w-full" /></div><div><x-input-label :for="'purchase-discount-'.$index" :value="__('Remise %')" /><x-text-input id="purchase-discount-{{ $index }}" type="number" min="0" max="100" step="0.01" wire:model.live="lines.{{ $index }}.discount_percent" class="mt-1 block w-full" /></div><div><x-input-label :for="'purchase-tax-'.$index" :value="__('TVA')" /><select id="purchase-tax-{{ $index }}" wire:model.live="lines.{{ $index }}.tax_rate_id" class="mt-1 block w-full border-gray-300 shadow-sm"><option value="">{{ __('TVA article/défaut') }}</option><option value="none">{{ __('Sans TVA') }}</option>@foreach ($taxRates as $taxRate)<option value="{{ $taxRate->id }}">{{ $taxRate->label }} · {{ $taxRate->rate }} %</option>@endforeach</select></div></div>
                        <div class="flex items-end justify-between gap-3 lg:col-span-2"><div class="text-end text-sm"><p class="text-gray-500">{{ __('TTC ligne') }}</p><p class="font-semibold text-gray-900">{{ isset($lineTotals[$index]) && $lineTotals[$index] ? str_replace('.', ',', $lineTotals[$index]['total_ttc']) : '—' }}</p></div><button type="button" wire:click="removeLine({{ $index }})" class="text-sm font-medium text-rose-700 hover:text-rose-900">{{ __('Retirer') }}</button></div>
                        <x-input-error :messages="$errors->get('lines.'.$index)" class="lg:col-span-12" />
                    </div>
                @endforeach
            </div>
        </section>

        <section class="flex flex-col gap-4 border-y border-gray-200 bg-white p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6"><dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm"><dt class="text-gray-600">{{ __('Brut HT') }}</dt><dd class="text-end font-medium">{{ str_replace('.', ',', $previewTotals['subtotal_ht']) }}</dd><dt class="text-gray-600">{{ __('Remises') }}</dt><dd class="text-end font-medium">{{ str_replace('.', ',', $previewTotals['discount_total']) }}</dd><dt class="text-gray-600">{{ __('TVA') }}</dt><dd class="text-end font-medium">{{ str_replace('.', ',', $previewTotals['tax_total']) }}</dd><dt class="font-semibold text-gray-900">{{ __('Total TTC') }}</dt><dd class="text-end font-semibold">{{ str_replace('.', ',', $previewTotals['total_ttc']) }}</dd></dl><x-primary-button>{{ $orderId === null ? __('Enregistrer le brouillon') : __('Enregistrer le brouillon') }}</x-primary-button></section>
    </form>
</section>
