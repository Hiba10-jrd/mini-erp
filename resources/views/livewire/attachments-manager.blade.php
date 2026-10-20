<?php

use App\Services\AttachmentAuthorizationService;
use App\Services\AttachmentManagementService;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public string $parentType;

    #[Locked]
    public int $parentId;

    #[Locked]
    public bool $excludeOriginalInvoice = false;

    public $file = null;

    public string $category = '';

    public string $customType = '';

    public const DOCUMENT_TYPES = ['Bon de commande', 'Devis signé', 'Contrat / convention', 'Justificatif', 'Document technique', 'Document administratif', 'Correspondance client'];

    public string $description = '';

    public function mount(string $parentType, int $parentId, bool $excludeOriginalInvoice = false): void
    {
        $this->parentType = $parentType;
        $this->parentId = $parentId;
        $this->excludeOriginalInvoice = $excludeOriginalInvoice && $parentType === 'supplier-invoice';
        $this->parent();
    }

    private function parent(): \Illuminate\Database\Eloquent\Model
    {
        $auth = app(AttachmentAuthorizationService::class);
        $parent = $auth->resolve($this->parentType, $this->parentId);
        $auth->authorize($parent);

        return $parent;
    }

    public function clearSelectedFile(): void
    {
        $this->reset('file');
        $this->resetValidation('file');
    }

    public function saveDocument(AttachmentManagementService $service): void
    {
        $this->parent();
        $this->customType = trim($this->customType);
        $this->validate([
            'file' => ['required', 'file'],
            'category' => ['nullable', \Illuminate\Validation\Rule::in([...self::DOCUMENT_TYPES, 'other'])],
            'customType' => ['exclude_unless:category,other', 'required_if:category,other', 'string', 'max:100'],
            'description' => ['string', 'max:2000'],
        ], [
            'file.required' => __('Choisissez un fichier à ajouter.'),
            'customType.required_if' => __('Précisez le type de document.'),
            'customType.max' => __('Le type de document ne doit pas dépasser 100 caractères.'),
        ]);
        $service->upload($this->parent(), $this->file, ['category' => ($this->category === 'other' ? $this->customType : $this->category) ?: null, 'description' => $this->description ?: null]);
        $this->reset('file', 'category', 'customType', 'description');
        $this->resetPage('attachmentsPage');
        session()->flash('attachment-status', __('Le document a été ajouté.'));
    }

    public function remove(int $id, AttachmentManagementService $service): void
    {
        $service->delete($this->parent()->attachments()->findOrFail($id));
        $this->resetPage('attachmentsPage');
        session()->flash('attachment-status', __('Le document a été supprimé.'));
    }

    public function with(): array
    {
        $parent = $this->parent();
        $authorization = app(AttachmentAuthorizationService::class);
        [$read, $write] = $authorization->permissions($parent);

        return [
            'documentTitle' => match ($this->parentType) {
                'invoice', 'supplier-invoice' => __('Documents liés à cette facture'),
                'credit-note' => __('Documents liés à cet avoir'),
                'payment', 'supplier-payment' => __('Documents liés à ce paiement'),
                'expense' => __('Documents liés à cette dépense'),
                'purchase-order' => __('Documents liés à cette commande'),
                'goods-receipt' => __('Documents liés à cette réception'),
            },
            'documentTypes' => self::DOCUMENT_TYPES,
            'documents' => $parent->attachments()->with('uploader:id,name')
                ->when($this->excludeOriginalInvoice, fn ($query) => $query->where(fn ($query) => $query->whereNull('category')->orWhere('category', '!=', \App\Models\SupplierInvoice::ORIGINAL_DOCUMENT_CATEGORY)))
                ->latest()->paginate(10, ['*'], 'attachmentsPage'),
            'canUpload' => \Illuminate\Support\Facades\Gate::allows($write),
            'canRemove' => \Illuminate\Support\Facades\Gate::allows($write) && $authorization->canDelete($parent),
            'legacyExpense' => $parent instanceof \App\Models\Expense && $parent->receipt_path ? $parent : null,
        ];
    }
}; ?>

<section class="mt-6 space-y-6 rounded-lg bg-white p-4 shadow-sm sm:p-6" aria-labelledby="documents-{{ $this->getId() }}">
    <header class="erp-section-heading">
        <h3 id="documents-{{ $this->getId() }}">{{ $documentTitle }}</h3>
        <p>{{ __('Ajoutez les pièces justificatives ou documents complémentaires associés à cette fiche : bon de commande, devis signé, contrat ou autre document utile.') }}</p>
    </header>
    @if (session('attachment-status'))<p role="status" class="rounded-lg bg-green-50 p-3 text-sm text-green-800">{{ session('attachment-status') }}</p>@endif
    @if ($legacyExpense)
        <a class="block text-indigo-600 underline" href="{{ route('attachments.expense-legacy', $legacyExpense) }}">{{ __('Télécharger le justificatif historique') }}</a>
    @endif
    @if ($canUpload)
        <form wire:submit="saveDocument" class="space-y-4" x-data="{ dragging: false, uploading: false, progress: 0, failed: false }"
            x-on:livewire-upload-start="uploading = true; failed = false; progress = 0"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-cancel="uploading = false"
            x-on:livewire-upload-error="uploading = false; failed = true"
            x-on:livewire-upload-progress="progress = $event.detail.progress">
            <div class="rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-6 text-center" :class="{ 'border-blue-500 bg-blue-50': dragging }"
                x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; if (!uploading && $event.dataTransfer.files.length) { $refs.documentFile.files = $event.dataTransfer.files; $refs.documentFile.dispatchEvent(new Event('change', { bubbles: true })); }">
                <svg class="mx-auto mb-3 h-8 w-8 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 16V4m-4 4 4-4 4 4M4 16v4h16v-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <p class="text-sm font-semibold text-gray-800">{{ __('Glissez-déposez un fichier ici') }}</p>
                <p class="my-2 text-xs text-gray-500">{{ __('ou') }}</p>
                <label class="relative inline-flex cursor-pointer rounded-lg border border-gray-300 bg-white px-4 py-2 focus-within:ring-2 focus-within:ring-blue-500 focus-within:ring-offset-2">
                    {{ __('Choisir un fichier') }}
                    <input x-ref="documentFile" type="file" wire:model="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="{{ __('Choisir un fichier') }}" aria-describedby="file-help-{{ $this->getId() }} file-error-{{ $this->getId() }}" :disabled="uploading" />
                </label>
                <p id="file-help-{{ $this->getId() }}" class="mt-3 text-xs text-gray-500">{{ __('PDF, JPG, PNG ou WebP · 5 Mo maximum · Un fichier à la fois') }}</p>
                @if ($file)
                    <div class="mt-3 flex flex-wrap items-center justify-center gap-3">
                        <p class="min-w-0 break-all text-sm font-medium" role="status">{{ __('Fichier sélectionné :') }} <bdi>{{ $file->getClientOriginalName() }}</bdi></p>
                        <button type="button" wire:click="clearSelectedFile" wire:loading.attr="disabled" :disabled="uploading" class="rounded px-2 py-1 text-sm text-red-600 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">{{ __('Retirer') }}</button>
                    </div>
                @endif
                <div x-cloak x-show="uploading" class="mt-3" role="status">
                    <p class="text-sm text-gray-600">{{ __('Transfert en cours…') }} <span x-text="progress + '%'"></span></p>
                    <progress class="mt-2 h-2 w-full" max="100" :value="progress" aria-label="{{ __('Progression du transfert') }}"></progress>
                </div>
                <p x-cloak x-show="failed" role="alert" class="mt-2 text-sm text-red-600">{{ __('Le transfert a échoué. Veuillez réessayer.') }}</p>
            </div>
            <div id="file-error-{{ $this->getId() }}" role="alert"><x-input-error :messages="$errors->get('file')" /></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-2">{{ __('Type de document') }}
                    <select wire:model.live="category" class="w-full" aria-describedby="type-error-{{ $this->getId() }}">
                        <option value="">{{ __('Sans type (facultatif)') }}</option>
                        @foreach ($documentTypes as $type)<option value="{{ $type }}">{{ __($type) }}</option>@endforeach
                        <option value="other">{{ __('Autre') }}</option>
                    </select>
                    <x-input-error id="type-error-{{ $this->getId() }}" role="alert" :messages="$errors->get('category')" />
                </label>
                @if ($category === 'other')
                    <label class="grid gap-2">{{ __('Préciser le type') }}
                        <input wire:model="customType" maxlength="100" class="w-full" placeholder="{{ __('Ex. : Procès-verbal d’intervention') }}" aria-required="true" aria-describedby="custom-error-{{ $this->getId() }}" />
                        <x-input-error id="custom-error-{{ $this->getId() }}" role="alert" :messages="$errors->get('customType')" />
                    </label>
                @endif
                <label class="grid gap-2 sm:col-span-2">{{ __('Description (facultative)') }}
                    <textarea wire:model="description" maxlength="2000" rows="2" class="w-full" placeholder="{{ __('Ajoutez une précision pour retrouver facilement ce document.') }}" aria-describedby="description-error-{{ $this->getId() }}"></textarea>
                    <x-input-error id="description-error-{{ $this->getId() }}" role="alert" :messages="$errors->get('description')" />
                </label>
            </div>
            <button type="submit" class="erp-button erp-button-primary w-full sm:w-auto" wire:loading.attr="disabled" :disabled="uploading">
                <span wire:loading.remove wire:target="saveDocument">{{ __('Ajouter le document') }}</span>
                <span wire:loading wire:target="saveDocument" role="status">{{ __('Ajout en cours…') }}</span>
            </button>
        </form>
    @endif
    <h4 class="border-t border-gray-200 pt-5 text-sm font-semibold">{{ __('Documents ajoutés') }} ({{ $documents->total() }})</h4>
    <ul class="divide-y divide-gray-200">
        @forelse ($documents as $document)
            <li wire:key="attachment-{{ $document->id }}" class="flex flex-col gap-4 py-4 lg:flex-row lg:items-start">
                <div class="flex min-w-0 flex-1 gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-xs font-semibold text-gray-600" aria-hidden="true">{{ $document->mime_type === 'application/pdf' ? 'PDF' : (str_starts_with($document->mime_type, 'image/') ? 'IMG' : 'DOC') }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="break-all text-sm font-semibold text-gray-900"><bdi>{{ $document->original_name }}</bdi></p>
                        <p class="mt-1 break-words text-xs font-medium text-indigo-600">{{ $document->category ?: __('Sans type') }}</p>
                        @if ($document->description)<p class="mt-2 whitespace-pre-line break-words text-sm text-gray-600">{{ $document->description }}</p>@endif
                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                            <span>{{ __('Ajouté par') }} : {{ $document->uploader?->name ?: __('Utilisateur indisponible') }}</span>
                            <time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->format('d/m/Y H:i') }}</time>
                            <span class="erp-ltr">{{ number_format($document->size / 1024, 1) }} {{ __('Ko') }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <a href="{{ route('attachments.download', $document) }}" class="erp-button erp-button-secondary" aria-label="{{ __('Télécharger') }} {{ $document->original_name }}">{{ __('Télécharger') }}</a>
                    @if ($canRemove)<button type="button" wire:click="remove({{ $document->id }})" wire:confirm="{{ __('Supprimer ce document ? Il ne sera plus disponible dans cette liste.') }}" wire:loading.attr="disabled" class="erp-button text-red-600" aria-label="{{ __('Supprimer') }} {{ $document->original_name }}">{{ __('Supprimer') }}</button>@endif
                </div>
            </li>
        @empty
            <li class="erp-empty-state rounded-lg bg-gray-50">
                <p class="font-medium text-gray-700">{{ __('Aucun document ajouté pour le moment.') }}</p>
                <p>{{ $canUpload ? __('Ajoutez votre premier document à l’aide du formulaire ci-dessus.') : __('Les documents associés à cette fiche apparaîtront ici.') }}</p>
            </li>
        @endforelse
    </ul>
    {{ $documents->links() }}
</section>
