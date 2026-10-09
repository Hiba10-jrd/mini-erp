<?php

use App\Models\Attachment;
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

    public $file = null;

    public string $category = '';

    public string $description = '';

    public function mount(string $parentType, int $parentId): void
    {
        $this->parentType = $parentType;
        $this->parentId = $parentId;
        $this->parent();
    }

    private function parent(): \Illuminate\Database\Eloquent\Model
    {
        $auth = app(AttachmentAuthorizationService::class);
        $parent = $auth->resolve($this->parentType, $this->parentId);
        $auth->authorize($parent);

        return $parent;
    }

    public function upload(AttachmentManagementService $service): void
    {
        $this->validate(['file' => ['required', 'file'], 'category' => ['string', 'max:100'], 'description' => ['string', 'max:2000']]);
        $service->upload($this->parent(), $this->file, ['category' => $this->category ?: null, 'description' => $this->description ?: null]);
        $this->reset('file', 'category', 'description');
        $this->resetPage('attachmentsPage');
    }

    public function remove(int $id, AttachmentManagementService $service): void
    {
        $service->delete($this->parent()->attachments()->findOrFail($id));
    }

    public function with(): array
    {
        $parent = $this->parent();
        $authorization = app(AttachmentAuthorizationService::class);
        [$read, $write] = $authorization->permissions($parent);

        return [
            'documents' => $parent->attachments()->with('uploader:id,name')->latest()->paginate(10, ['*'], 'attachmentsPage'),
            'canUpload' => \Illuminate\Support\Facades\Gate::allows($write),
            'canRemove' => \Illuminate\Support\Facades\Gate::allows($write) && $authorization->canDelete($parent),
            'legacyExpense' => $parent instanceof \App\Models\Expense && $parent->receipt_path ? $parent : null,
        ];
    }
}; ?>

<section class="mt-6 space-y-4 rounded-lg bg-white p-6 shadow-sm">
    <h3 class="text-lg font-semibold">{{ __('Documents et pièces jointes') }}</h3>
    @if ($legacyExpense)
        <a class="block text-indigo-600 underline" href="{{ route('attachments.expense-legacy', $legacyExpense) }}">{{ __('Télécharger le justificatif historique') }}</a>
    @endif
    @if ($canUpload)
        <form wire:submit="upload" class="space-y-3">
            <label class="block">{{ __('Document (PDF, JPEG, PNG, WebP — 5 Mo maximum)') }}
                <input type="file" wire:model="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="block" />
            </label>
            <x-input-error :messages="$errors->get('file')" />
            <label class="block">{{ __('Catégorie') }} <input wire:model="category" maxlength="100" class="rounded border-gray-300" /></label>
            <x-input-error :messages="$errors->get('category')" />
            <label class="block">{{ __('Description') }} <input wire:model="description" maxlength="2000" class="rounded border-gray-300" /></label>
            <x-input-error :messages="$errors->get('description')" />
            <x-primary-button wire:loading.attr="disabled">{{ __('Ajouter') }}</x-primary-button>
        </form>
    @endif
    <ul class="divide-y">
        @forelse ($documents as $document)
            <li wire:key="attachment-{{ $document->id }}" class="flex flex-wrap items-center gap-3 py-3">
                <a href="{{ route('attachments.download', $document) }}" class="text-indigo-600 underline">{{ $document->original_name }}</a>
                <span class="text-sm text-gray-500"><span class="erp-ltr">{{ number_format($document->size / 1024, 1) }} {{ __('Ko') }}</span> · {{ $document->uploader?->name }}</span>
                <span>{{ $document->category }} {{ $document->description }}</span>
                @if ($canRemove)<button wire:click="remove({{ $document->id }})" wire:confirm="{{ __('Retirer cette pièce ?') }}" class="text-red-600">{{ __('Retirer') }}</button>@endif
            </li>
        @empty <li class="py-3 text-gray-500">{{ __('Aucune pièce jointe.') }}</li> @endforelse
    </ul>
    {{ $documents->links() }}
</section>
