<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Services\ProductManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $typeFilter = 'all';

    public string $categoryFilter = 'all';

    public string $statusFilter = 'active';

    public string $type = 'product';

    public string $reference = '';

    public string $barcode = '';

    public string $name = '';

    public string $description = '';

    public string $categoryId = '';

    public string $brand = '';

    public string $unitId = '';

    public string $purchasePrice = '0.00';

    public string $sellingPrice = '0.00';

    public string $taxRateId = '';

    public string $minimumStock = '';

    public string $maximumStock = '';

    public ?TemporaryUploadedFile $image = null;

    #[Locked]
    public ?int $editingId = null;

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('products.access');
    }

    public function with(): array
    {
        Gate::authorize('products.access');
        $type = in_array($this->typeFilter, ['all', 'product', 'service'], true) ? $this->typeFilter : 'all';
        $status = in_array($this->statusFilter, ['active', 'inactive', 'all'], true) ? $this->statusFilter : 'active';
        $categoryId = ctype_digit($this->categoryFilter) ? (int) $this->categoryFilter : null;
        $search = trim($this->search);
        $editingProduct = $this->editingId === null ? null : Product::query()->find($this->editingId);

        return [
            'products' => Product::query()
                ->with(['category', 'unit', 'taxRate'])
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->where('reference', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
                })
                ->when($type !== 'all', fn ($query) => $query->where('type', $type))
                ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
                ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(10),
            'categories' => Category::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'units' => Unit::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'taxRates' => TaxRate::query()->orderByDesc('is_active')->orderBy('rate')->orderBy('label')->get(),
            'editingImageUrl' => $editingProduct?->image_path
                ? Storage::disk('public')->url($editingProduct->image_path)
                : null,
        ];
    }

    public function updatingSearch(): void
    {
        Gate::authorize('products.access');
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        Gate::authorize('products.access');
        $this->resetPage();
    }

    public function updatingCategoryFilter(): void
    {
        Gate::authorize('products.access');
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        Gate::authorize('products.access');
        $this->resetPage();
    }

    public function updatedType(): void
    {
        Gate::authorize('stock.manage');

        if ($this->type === 'service') {
            $this->minimumStock = '';
            $this->maximumStock = '';
        }
    }

    public function updatedImage(): void
    {
        Gate::authorize('stock.manage');
        $this->validateOnly('image', ['image' => $this->imageRules()]);
    }

    public function prepareCreate(): void
    {
        Gate::authorize('stock.manage');
        $this->resetForm();
        $this->feedback = null;
        $this->dispatch('open-modal', name: 'manage-product');
    }

    public function editProduct(int $productId): void
    {
        Gate::authorize('stock.manage');
        $product = Product::query()->findOrFail($productId);
        $this->editingId = $product->id;
        $this->type = $product->type;
        $this->reference = $product->reference;
        $this->barcode = $product->barcode ?? '';
        $this->name = $product->name;
        $this->description = $product->description ?? '';
        $this->categoryId = (string) ($product->category_id ?? '');
        $this->brand = $product->brand ?? '';
        $this->unitId = (string) $product->unit_id;
        $this->purchasePrice = (string) $product->purchase_price;
        $this->sellingPrice = (string) $product->selling_price;
        $this->taxRateId = (string) ($product->tax_rate_id ?? '');
        $this->minimumStock = $product->minimum_stock === null ? '' : (string) $product->minimum_stock;
        $this->maximumStock = $product->maximum_stock === null ? '' : (string) $product->maximum_stock;
        $this->image = null;
        $this->feedback = null;
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'manage-product');
    }

    public function saveProduct(ProductManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $this->reference = Str::upper(trim($this->reference));
        $this->barcode = trim($this->barcode);
        $this->name = trim($this->name);
        $validated = $this->validate($this->rules());

        if (
            $validated['type'] === 'product'
            && $validated['minimumStock'] !== ''
            && $validated['maximumStock'] !== ''
            && (float) $validated['maximumStock'] < (float) $validated['minimumStock']
        ) {
            throw ValidationException::withMessages([
                'maximumStock' => __('Le stock maximum doit être supérieur ou égal au stock minimum.'),
            ]);
        }

        $attributes = [
            'type' => $validated['type'],
            'reference' => $validated['reference'],
            'barcode' => $this->nullableString($validated['barcode']),
            'name' => $validated['name'],
            'description' => $this->nullableString($validated['description']),
            'category_id' => $validated['categoryId'] === '' ? null : $validated['categoryId'],
            'brand' => $this->nullableString($validated['brand']),
            'unit_id' => $validated['unitId'],
            'purchase_price' => $validated['purchasePrice'],
            'selling_price' => $validated['sellingPrice'],
            'tax_rate_id' => $validated['taxRateId'] === '' ? null : $validated['taxRateId'],
            'minimum_stock' => $validated['type'] === 'service' || $validated['minimumStock'] === '' ? null : $validated['minimumStock'],
            'maximum_stock' => $validated['type'] === 'service' || $validated['maximumStock'] === '' ? null : $validated['maximumStock'],
        ];

        $product = $service->save($this->editingId, $attributes, $this->image);
        $this->feedback = $this->editingId === null
            ? __('Article :reference créé.', ['reference' => $product->reference])
            : __('Article mis à jour.');
        $this->resetForm();
        $this->dispatch('close-modal', name: 'manage-product');
    }

    public function toggleProduct(int $productId, ProductManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $product = Product::query()->findOrFail($productId);
        $service->setActive($product->id, ! $product->is_active);
        $this->feedback = $product->is_active ? __('Article désactivé.') : __('Article activé.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['product', 'service'])],
            'reference' => [
                'required',
                'string',
                'max:100',
                Rule::unique(Product::class, 'reference')->ignore($this->editingId),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique(Product::class, 'barcode')->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'categoryId' => ['nullable', 'integer', Rule::exists(Category::class, 'id')],
            'brand' => ['nullable', 'string', 'max:255'],
            'unitId' => ['required', 'integer', Rule::exists(Unit::class, 'id')],
            'purchasePrice' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'sellingPrice' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'taxRateId' => ['nullable', 'integer', Rule::exists(TaxRate::class, 'id')],
            'minimumStock' => $this->type === 'service'
                ? ['prohibited']
                : ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999999.999'],
            'maximumStock' => $this->type === 'service'
                ? ['prohibited']
                : ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999999.999'],
            'image' => $this->imageRules(),
        ];
    }

    /** @return array<int, string> */
    private function imageRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'reference', 'barcode', 'name', 'description', 'categoryId', 'brand', 'unitId', 'taxRateId', 'minimumStock', 'maximumStock', 'image');
        $this->type = 'product';
        $this->purchasePrice = '0.00';
        $this->sellingPrice = '0.00';
        $this->resetValidation();
    }
}; ?>

<div class="space-y-6">
    @if ($feedback)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $feedback }}</div>
    @endif

    <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div class="border-b border-gray-200 p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Produits et services') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Catalogue commercial sans quantité de stock ni mouvement de dépôt.') }}</p>
                </div>
                @can('stock.manage')
                    <x-primary-button type="button" wire:click="prepareCreate">{{ __('Nouvel article') }}</x-primary-button>
                @endcan
            </div>

            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <x-input-label for="product-search" :value="__('Rechercher')" />
                    <x-text-input id="product-search" type="search" wire:model.live.debounce.300ms="search" class="mt-1 block w-full" placeholder="{{ __('Référence, code-barres, désignation') }}" />
                </div>
                <div>
                    <x-input-label for="product-type-filter" :value="__('Type')" />
                    <select id="product-type-filter" wire:model.live="typeFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="all">{{ __('Tous') }}</option>
                        <option value="product">{{ __('Produits') }}</option>
                        <option value="service">{{ __('Services') }}</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="product-category-filter" :value="__('Catégorie')" />
                    <select id="product-category-filter" wire:model.live="categoryFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="all">{{ __('Toutes') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="product-status-filter" :value="__('Statut')" />
                    <select id="product-status-filter" wire:model.live="statusFilter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="active">{{ __('Actifs') }}</option>
                        <option value="inactive">{{ __('Inactifs') }}</option>
                        <option value="all">{{ __('Tous') }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Référence') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Désignation') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Type') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Catégorie') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Prix vente') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('TVA') }}</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">{{ __('Statut') }}</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase text-gray-600">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse ($products as $product)
                        <tr wire:key="product-{{ $product->id }}">
                            <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">{{ $product->reference }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <a href="{{ route('admin.products.show', $product) }}" wire:navigate class="text-indigo-600 hover:text-indigo-900">{{ $product->name }}</a>
                                <div class="text-xs text-gray-500">{{ $product->brand }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $product->isService() ? __('Service') : __('Produit') }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $product->category?->name ?? __('Non classé') }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">{{ number_format((float) $product->selling_price, 2, ',', ' ') }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">{{ $product->taxRate ? number_format((float) $product->taxRate->rate, 2, ',', ' ').' %' : __('Aucune') }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $product->is_active ? __('Actif') : __('Inactif') }}</td>
                            <td class="px-6 py-4 text-right text-sm">
                                <div class="flex flex-wrap justify-end gap-3">
                                    <a href="{{ route('admin.products.show', $product) }}" wire:navigate class="text-indigo-600 hover:text-indigo-900">{{ __('Consulter') }}</a>
                                    @can('stock.manage')
                                        <button type="button" wire:click="editProduct({{ $product->id }})" class="text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button>
                                        <button type="button" wire:click="toggleProduct({{ $product->id }})" class="text-gray-600 hover:text-gray-900">{{ $product->is_active ? __('Désactiver') : __('Activer') }}</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">{{ __('Aucun article trouvé.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="border-t border-gray-200 px-6 py-4">{{ $products->links() }}</div>
        @endif
    </section>

    @can('stock.manage')
        <x-modal name="manage-product" focusable maxWidth="2xl">
            <form wire:submit="saveProduct" class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">{{ $editingId ? __('Modifier l’article') : __('Nouvel article') }}</h3>
                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="product-type" :value="__('Type')" />
                        <select id="product-type" wire:model.live="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="product">{{ __('Produit physique') }}</option>
                            <option value="service">{{ __('Service') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-reference" :value="__('Référence')" />
                        <x-text-input id="product-reference" wire:model="reference" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('reference')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-name" :value="__('Désignation')" />
                        <x-text-input id="product-name" wire:model="name" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-barcode" :value="__('Code-barres')" />
                        <x-text-input id="product-barcode" wire:model="barcode" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('barcode')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-category" :value="__('Catégorie')" />
                        <select id="product-category" wire:model="categoryId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('Non classé') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}{{ $category->is_active ? '' : ' — '.__('inactive') }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('categoryId')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-unit" :value="__('Unité')" />
                        <select id="product-unit" wire:model="unitId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('Sélectionner') }}</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->symbol }}){{ $unit->is_active ? '' : ' — '.__('inactive') }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('unitId')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-brand" :value="__('Marque')" />
                        <x-text-input id="product-brand" wire:model="brand" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('brand')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-tax-rate" :value="__('TVA')" />
                        <select id="product-tax-rate" wire:model="taxRateId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('Aucune TVA sélectionnée') }}</option>
                            @foreach ($taxRates as $taxRate)
                                <option value="{{ $taxRate->id }}">{{ $taxRate->label }} — {{ number_format((float) $taxRate->rate, 2, ',', ' ') }} %{{ $taxRate->is_active ? '' : ' — '.__('inactive') }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('taxRateId')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-purchase-price" :value="__('Prix d’achat')" />
                        <x-text-input id="product-purchase-price" type="number" step="0.01" min="0" wire:model="purchasePrice" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('purchasePrice')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="product-selling-price" :value="__('Prix de vente')" />
                        <x-text-input id="product-selling-price" type="number" step="0.01" min="0" wire:model="sellingPrice" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('sellingPrice')" class="mt-2" />
                    </div>
                    @if ($type === 'product')
                        <div>
                            <x-input-label for="product-minimum-stock" :value="__('Stock minimum préparatoire')" />
                            <x-text-input id="product-minimum-stock" type="number" step="0.001" min="0" wire:model="minimumStock" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('minimumStock')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="product-maximum-stock" :value="__('Stock maximum préparatoire')" />
                            <x-text-input id="product-maximum-stock" type="number" step="0.001" min="0" wire:model="maximumStock" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('maximumStock')" class="mt-2" />
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <x-input-label for="product-description" :value="__('Description')" />
                        <textarea id="product-description" wire:model="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="product-image" :value="__('Photo')" />
                        <input id="product-image" type="file" wire:model="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="mt-1 block w-full rounded-md border border-gray-300 bg-white text-sm text-gray-700 shadow-sm file:mr-4 file:border-0 file:bg-gray-100 file:px-4 file:py-2" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('JPG, PNG ou WEBP, 4 Mo maximum.') }}</p>
                        <x-input-error :messages="$errors->get('image')" class="mt-2" />
                        <div wire:loading wire:target="image" class="mt-2 text-sm text-gray-500">{{ __('Téléversement en cours...') }}</div>
                    </div>
                    @if (($image && $image->isPreviewable()) || $editingImageUrl)
                        <div class="sm:col-span-2">
                            <img src="{{ $image && $image->isPreviewable() ? $image->temporaryUrl() : $editingImageUrl }}" alt="{{ __('Aperçu de l’article') }}" class="max-h-40 rounded-lg border border-gray-200 object-contain" />
                        </div>
                    @endif
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Annuler') }}</x-secondary-button>
                    <x-primary-button type="submit">{{ $editingId ? __('Enregistrer') : __('Créer') }}</x-primary-button>
                </div>
            </form>
        </x-modal>
    @endcan
</div>
