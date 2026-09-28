<?php

use App\Models\Category;
use App\Models\Unit;
use App\Services\CatalogManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public ?int $editingCategoryId = null;

    #[Locked]
    public ?int $editingUnitId = null;

    public string $categoryName = '';

    public string $categoryDescription = '';

    public string $unitName = '';

    public string $unitSymbol = '';

    public ?string $feedback = null;

    public function mount(): void
    {
        Gate::authorize('products.access');
    }

    public function with(): array
    {
        Gate::authorize('products.access');

        return [
            'categories' => Category::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'units' => Unit::query()->orderByDesc('is_active')->orderBy('name')->get(),
        ];
    }

    public function editCategory(int $categoryId): void
    {
        Gate::authorize('stock.manage');
        $category = Category::query()->findOrFail($categoryId);
        $this->editingCategoryId = $category->id;
        $this->categoryName = $category->name;
        $this->categoryDescription = $category->description ?? '';
        $this->feedback = null;
        $this->resetValidation();
    }

    public function saveCategory(CatalogManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $this->categoryName = trim($this->categoryName);
        $validated = $this->validate([
            'categoryName' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Category::class, 'name')->ignore($this->editingCategoryId),
            ],
            'categoryDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        $category = $this->editingCategoryId === null
            ? null
            : Category::query()->findOrFail($this->editingCategoryId);
        $service->saveCategory($this->editingCategoryId, [
            'name' => $validated['categoryName'],
            'description' => $this->nullableString($validated['categoryDescription']),
            'is_active' => $category?->is_active ?? true,
        ]);

        $this->feedback = $this->editingCategoryId === null
            ? __('Catégorie créée.')
            : __('Catégorie mise à jour.');
        $this->resetCategoryForm();
    }

    public function toggleCategory(int $categoryId, CatalogManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $category = Category::query()->findOrFail($categoryId);
        $service->setCategoryActive($category->id, ! $category->is_active);
        $this->feedback = $category->is_active ? __('Catégorie désactivée.') : __('Catégorie activée.');
    }

    public function editUnit(int $unitId): void
    {
        Gate::authorize('stock.manage');
        $unit = Unit::query()->findOrFail($unitId);
        $this->editingUnitId = $unit->id;
        $this->unitName = $unit->name;
        $this->unitSymbol = $unit->symbol;
        $this->feedback = null;
        $this->resetValidation();
    }

    public function saveUnit(CatalogManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $this->unitName = trim($this->unitName);
        $this->unitSymbol = trim($this->unitSymbol);
        $validated = $this->validate([
            'unitName' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Unit::class, 'name')->ignore($this->editingUnitId),
            ],
            'unitSymbol' => [
                'required',
                'string',
                'max:30',
                Rule::unique(Unit::class, 'symbol')->ignore($this->editingUnitId),
            ],
        ]);

        $unit = $this->editingUnitId === null
            ? null
            : Unit::query()->findOrFail($this->editingUnitId);
        $service->saveUnit($this->editingUnitId, [
            'name' => $validated['unitName'],
            'symbol' => $validated['unitSymbol'],
            'is_active' => $unit?->is_active ?? true,
        ]);

        $this->feedback = $this->editingUnitId === null
            ? __('Unité créée.')
            : __('Unité mise à jour.');
        $this->resetUnitForm();
    }

    public function toggleUnit(int $unitId, CatalogManagementService $service): void
    {
        Gate::authorize('stock.manage');
        $unit = Unit::query()->findOrFail($unitId);
        $service->setUnitActive($unit->id, ! $unit->is_active);
        $this->feedback = $unit->is_active ? __('Unité désactivée.') : __('Unité activée.');
    }

    public function resetCategoryForm(): void
    {
        Gate::authorize('stock.manage');
        $this->reset('editingCategoryId', 'categoryName', 'categoryDescription');
        $this->resetValidation();
    }

    public function resetUnitForm(): void
    {
        Gate::authorize('stock.manage');
        $this->reset('editingUnitId', 'unitName', 'unitSymbol');
        $this->resetValidation();
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}; ?>

<section class="space-y-4">
    <div>
        <h3 class="text-lg font-semibold text-gray-900">{{ __('Configuration du catalogue') }}</h3>
        <p class="mt-1 text-sm text-gray-600">{{ __('Les catégories et unités restent configurables et ne sont jamais supprimées depuis cette interface.') }}</p>
    </div>

    @if ($feedback)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ $feedback }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 p-6">
                <h4 class="font-semibold text-gray-900">{{ __('Catégories') }}</h4>
                @can('stock.manage')
                    <form wire:submit="saveCategory" class="mt-4 space-y-4">
                        <div>
                            <x-input-label for="category-name" :value="__('Nom')" />
                            <x-text-input id="category-name" wire:model="categoryName" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('categoryName')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="category-description" :value="__('Description')" />
                            <textarea id="category-description" wire:model="categoryDescription" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
                            <x-input-error :messages="$errors->get('categoryDescription')" class="mt-2" />
                        </div>
                        <div class="flex gap-3">
                            <x-primary-button type="submit">{{ $editingCategoryId ? __('Enregistrer') : __('Ajouter') }}</x-primary-button>
                            @if ($editingCategoryId)
                                <x-secondary-button type="button" wire:click="resetCategoryForm">{{ __('Annuler') }}</x-secondary-button>
                            @endif
                        </div>
                    </form>
                @endcan
            </div>
            <ul class="divide-y divide-gray-200">
                @forelse ($categories as $category)
                    <li class="flex items-start justify-between gap-4 px-6 py-4" wire:key="category-{{ $category->id }}">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $category->name }}</p>
                            <p class="text-xs text-gray-500">{{ $category->description }}</p>
                            <span class="text-xs {{ $category->is_active ? 'text-emerald-700' : 'text-gray-500' }}">{{ $category->is_active ? __('Active') : __('Inactive') }}</span>
                        </div>
                        @can('stock.manage')
                            <div class="flex shrink-0 gap-3 text-sm">
                                <button type="button" wire:click="editCategory({{ $category->id }})" class="text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button>
                                <button type="button" wire:click="toggleCategory({{ $category->id }})" class="text-gray-600 hover:text-gray-900">{{ $category->is_active ? __('Désactiver') : __('Activer') }}</button>
                            </div>
                        @endcan
                    </li>
                @empty
                    <li class="px-6 py-6 text-center text-sm text-gray-500">{{ __('Aucune catégorie.') }}</li>
                @endforelse
            </ul>
        </div>

        <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
            <div class="border-b border-gray-200 p-6">
                <h4 class="font-semibold text-gray-900">{{ __('Unités') }}</h4>
                @can('stock.manage')
                    <form wire:submit="saveUnit" class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="unit-name" :value="__('Nom')" />
                            <x-text-input id="unit-name" wire:model="unitName" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('unitName')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="unit-symbol" :value="__('Symbole')" />
                            <x-text-input id="unit-symbol" wire:model="unitSymbol" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('unitSymbol')" class="mt-2" />
                        </div>
                        <div class="flex gap-3 sm:col-span-2">
                            <x-primary-button type="submit">{{ $editingUnitId ? __('Enregistrer') : __('Ajouter') }}</x-primary-button>
                            @if ($editingUnitId)
                                <x-secondary-button type="button" wire:click="resetUnitForm">{{ __('Annuler') }}</x-secondary-button>
                            @endif
                        </div>
                    </form>
                @endcan
            </div>
            <ul class="divide-y divide-gray-200">
                @forelse ($units as $unit)
                    <li class="flex items-center justify-between gap-4 px-6 py-4" wire:key="unit-{{ $unit->id }}">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $unit->name }} <span class="text-gray-500">({{ $unit->symbol }})</span></p>
                            <span class="text-xs {{ $unit->is_active ? 'text-emerald-700' : 'text-gray-500' }}">{{ $unit->is_active ? __('Active') : __('Inactive') }}</span>
                        </div>
                        @can('stock.manage')
                            <div class="flex shrink-0 gap-3 text-sm">
                                <button type="button" wire:click="editUnit({{ $unit->id }})" class="text-indigo-600 hover:text-indigo-900">{{ __('Modifier') }}</button>
                                <button type="button" wire:click="toggleUnit({{ $unit->id }})" class="text-gray-600 hover:text-gray-900">{{ $unit->is_active ? __('Désactiver') : __('Activer') }}</button>
                            </div>
                        @endcan
                    </li>
                @empty
                    <li class="px-6 py-6 text-center text-sm text-gray-500">{{ __('Aucune unité.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</section>
