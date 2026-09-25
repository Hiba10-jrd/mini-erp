<?php

use App\Models\DocumentSequence;
use App\Services\DocumentSequenceManagementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public ?int $editingId = null;

    public string $documentType = 'quote';

    public string $prefix = 'DEV';

    public string $year = '';

    public string $counter = '0';

    public string $numberFormat = '{prefix}-{year}-{counter:05d}';

    public ?string $feedback = null;

    private bool $loadingSequence = false;

    /**
     * Initialize the component.
     */
    public function mount(): void
    {
        Gate::authorize('company.administer');

        $this->year = (string) now()->year;
    }

    /**
     * Load existing document sequences.
     */
    public function with(): array
    {
        Gate::authorize('company.administer');

        return [
            'sequences' => DocumentSequence::query()
                ->orderByDesc('year')
                ->orderBy('document_type')
                ->get(),

            'documentTypes' => $this->documentTypes(),
        ];
    }

    /**
     * Automatically suggest a prefix when the
     * document type is changed by the user.
     */
    public function updatedDocumentType(string $value): void
    {
        Gate::authorize('company.administer');

        if ($this->loadingSequence) {
            return;
        }

        $this->prefix = $this->prefixes()[$value] ?? '';

        $this->resetValidation('documentType');
        $this->resetValidation('prefix');
    }

    /**
     * Load an existing sequence for editing.
     */
    public function edit(int $sequenceId): void
    {
        Gate::authorize('company.administer');

        $sequence = DocumentSequence::query()
            ->findOrFail($sequenceId);

        $this->editingId = $sequence->id;

        $this->loadingSequence = true;
        $this->documentType = $sequence->document_type;
        $this->loadingSequence = false;

        // Preserve the prefix already saved in MySQL.
        $this->prefix = $sequence->prefix;

        $this->year = (string) $sequence->year;

        $this->counter = (string) $sequence->counter;

        $this->numberFormat = $sequence->number_format;

        $this->feedback = null;

        $this->resetValidation();
        $this->dispatch('sequence-edit-started');
    }

    /**
     * Save a new or existing document sequence.
     */
    public function save(
        DocumentSequenceManagementService $service
    ): void {
        Gate::authorize('company.administer');

        $validated = $this->validate($this->rules());

        $service->save($this->editingId, [
            'document_type' => $validated['documentType'],
            'prefix' => $validated['prefix'],
            'year' => $validated['year'],
            'counter' => $validated['counter'],
            'number_format' => $validated['numberFormat'],
        ]);

        $this->feedback = __(
            'La numérotation a été enregistrée.'
        );

        $this->resetForm();
    }

    /**
     * Prepare the form for a new sequence.
     */
    public function prepareCreate(): void
    {
        Gate::authorize('company.administer');

        $this->editingId = null;

        $this->documentType = 'quote';

        // Default prefix for a new quote.
        $this->prefix = 'DEV';

        $this->year = (string) now()->year;

        $this->counter = '0';

        $this->numberFormat = '{prefix}-{year}-{counter:05d}';

        $this->feedback = null;

        $this->resetValidation();
        $this->dispatch('sequence-create-started');
    }

    /**
     * Preview the next number without consuming it.
     */
    public function previewNumber(
        DocumentSequenceManagementService $service
    ): string {
        Gate::authorize('company.administer');

        return $service->preview([
            'prefix' => $this->prefix ?: 'DOC',
            'year' => (int) ($this->year ?: now()->year),
            'counter' => (int) $this->counter,
            'number_format' => $this->numberFormat,
        ]);
    }

    /**
     * Validation rules.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'prefix' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/',
            ],

            'year' => [
                'required',
                'integer',
                'between:2000,2100',
            ],

            'counter' => [
                'required',
                'integer',
                'min:0',
                'max:999999999999',
            ],

            'numberFormat' => [
                'required',
                'string',
                'max:100',
                'regex:/^(?=.*\{prefix\})(?=.*\{year\})(?=.*\{counter(?::0*[1-9][0-9]*d)?\})[A-Za-z0-9{}:_\-\/\s]+$/',
            ],

            'documentType' => [
                'required',
                'string',

                Rule::in(
                    array_keys($this->documentTypes())
                ),

                Rule::unique(
                    DocumentSequence::class,
                    'document_type'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'year',
                            $this->year
                        )
                    )
                    ->ignore($this->editingId),
            ],
        ];
    }

    /**
     * Available document types.
     *
     * @return array<string, string>
     */
    private function documentTypes(): array
    {
        return [
            'quote' => 'Devis',
            'order' => 'Commande',
            'delivery_note' => 'Bon de livraison',
            'invoice' => 'Facture',
            'credit_note' => 'Avoir',
        ];
    }

    /**
     * Prefix suggestions for new type selections.
     *
     * @return array<string, string>
     */
    private function prefixes(): array
    {
        return [
            'quote' => 'DEV',
            'order' => 'CMD',
            'delivery_note' => 'BL',
            'invoice' => 'FAC',
            'credit_note' => 'AV',
        ];
    }

    /**
     * Reset the form after saving.
     */
    private function resetForm(): void
    {
        $this->editingId = null;

        $this->documentType = 'quote';

        $this->prefix = 'DEV';

        $this->year = (string) now()->year;

        $this->counter = '0';

        $this->numberFormat = '{prefix}-{year}-{counter:05d}';

        $this->resetValidation();
    }
};

?>

<section
    id="sequence-settings-form"
    x-data
    x-on:sequence-edit-started.window="$nextTick(() => document.getElementById('sequence-settings-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
    class="overflow-hidden bg-white shadow-sm sm:rounded-lg"
>

    <!-- HEADER -->

    <div class="border-b border-gray-200 p-6">

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

            <div>
                <h3 class="text-lg font-semibold text-gray-900">
                    {{ __('Numérotation des documents') }}
                </h3>

                <p class="mt-1 text-sm text-gray-600">
                    {{ __('L’aperçu ne consomme jamais de numéro. L’attribution définitive sera gérée par les futurs modules documentaires.') }}
                </p>
            </div>

            <x-secondary-button
                type="button"
                wire:click="prepareCreate"
            >
                {{ __('Nouvelle séquence') }}
            </x-secondary-button>

        </div>

    </div>

    <!-- FORM -->

    @if ($editingId)
        <div class="border-b border-indigo-100 bg-indigo-50 px-6 py-4" role="status">
            <p class="font-semibold text-indigo-900">{{ __('Mode modification') }}</p>
            <p class="mt-1 text-sm text-indigo-800">
                {{ $documentTypes[$documentType] ?? $documentType }} · {{ $prefix }} · {{ $year }}
            </p>
        </div>
    @endif

    <form
        id="sequence-form"
        wire:submit="save"
        class="grid gap-5 border-b border-gray-200 p-6 sm:grid-cols-2"
    >

        <!-- DOCUMENT TYPE -->

        <div>

            <x-input-label
                for="sequence-type"
                :value="__('Type de document')"
            />

            <select
                id="sequence-type"
                wire:model.change="documentType"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >

                @foreach ($documentTypes as $type => $label)

                    <option value="{{ $type }}">
                        {{ $label }}
                    </option>

                @endforeach

            </select>

            <x-input-error
                :messages="$errors->get('documentType')"
                class="mt-2"
            />

        </div>

        <!-- PREFIX -->

        <div>

            <x-input-label
                for="sequence-prefix"
                :value="__('Préfixe')"
            />

            <x-text-input
                id="sequence-prefix"
                wire:model.live.debounce.300ms="prefix"
                class="mt-1 block w-full uppercase"
                placeholder="DEV"
            />

            <p class="mt-1 text-xs text-gray-500">
                {{ __('Le préfixe est proposé automatiquement lors de la création, mais reste personnalisable.') }}
            </p>

            <x-input-error
                :messages="$errors->get('prefix')"
                class="mt-2"
            />

        </div>

        <!-- YEAR -->

        <div>

            <x-input-label
                for="sequence-year"
                :value="__('Année')"
            />

            <x-text-input
                id="sequence-year"
                type="number"
                wire:model.change="year"
                class="mt-1 block w-full"
            />

            <x-input-error
                :messages="$errors->get('year')"
                class="mt-2"
            />

        </div>

        <!-- COUNTER -->

        <div>

            <x-input-label
                for="sequence-counter"
                :value="__('Compteur actuel')"
            />

            <x-text-input
                id="sequence-counter"
                type="number"
                min="0"
                wire:model.change="counter"
                class="mt-1 block w-full"
            />

            <x-input-error
                :messages="$errors->get('counter')"
                class="mt-2"
            />

        </div>

        <!-- NUMBER FORMAT -->

        <div class="sm:col-span-2">

            <x-input-label
                for="sequence-format"
                :value="__('Format')"
            />

            <x-text-input
                id="sequence-format"
                wire:model.change="numberFormat"
                class="mt-1 block w-full font-mono"
            />

            <p class="mt-1 text-xs text-gray-500">
                {{ __('Placeholders autorisés : {prefix}, {year}, {counter} ou {counter:05d}.') }}
            </p>

            <x-input-error
                :messages="$errors->get('numberFormat')"
                class="mt-2"
            />

        </div>

        <!-- NUMBER PREVIEW -->

        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 sm:col-span-2">

            <span class="font-medium">
                {{ __('Aperçu :') }}
            </span>

            <span class="font-mono">
                {{ $this->previewNumber(app(DocumentSequenceManagementService::class)) }}
            </span>

        </div>

        <!-- ACTIONS -->

        <div class="flex gap-3 sm:col-span-2">

            <x-primary-button type="submit">
                {{ $editingId ? __('Enregistrer les modifications') : __('Ajouter') }}

            </x-primary-button>

            @if ($editingId)

                <x-secondary-button
                    type="button"
                    wire:click="prepareCreate"
                >
                    {{ __('Annuler') }}
                </x-secondary-button>

            @endif

        </div>

    </form>

    <!-- SUCCESS MESSAGE -->

    @if ($feedback)

        <p
            class="border-b border-gray-200 px-6 py-3 text-sm text-emerald-700"
            role="status"
        >
            {{ $feedback }}
        </p>

    @endif

    <!-- EXISTING SEQUENCES -->

    <div class="overflow-x-auto">

        <table class="min-w-full divide-y divide-gray-200">

            <thead class="bg-gray-50">

                <tr>

                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">
                        {{ __('Type') }}
                    </th>

                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">
                        {{ __('Préfixe / année') }}
                    </th>

                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase text-gray-600">
                        {{ __('Compteur') }}
                    </th>

                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase text-gray-600">
                        {{ __('Action') }}
                    </th>

                </tr>

            </thead>

            <tbody class="divide-y divide-gray-200 bg-white">

                @forelse ($sequences as $sequence)

                    <tr
                        wire:key="sequence-{{ $sequence->id }}"
                        @class([
                            'bg-indigo-50 ring-1 ring-inset ring-indigo-200' => $editingId === $sequence->id,
                            'hover:bg-gray-50' => $editingId !== $sequence->id,
                        ])
                    >

                        <td class="px-6 py-4 text-sm text-gray-900">
                            {{ $documentTypes[$sequence->document_type] ?? $sequence->document_type }}
                        </td>

                        <td class="px-6 py-4 text-sm text-gray-600">
                            {{ $sequence->prefix }} / {{ $sequence->year }}
                        </td>

                        <td class="px-6 py-4 text-sm text-gray-600">
                            {{ $sequence->counter }}
                        </td>

                        <td class="px-6 py-4 text-right">

                            <button
                                type="button"
                                wire:click="edit({{ $sequence->id }})"
                                class="text-sm text-indigo-600 hover:text-indigo-900"
                            >
                                {{ __('Modifier') }}
                            </button>

                        </td>

                    </tr>

                @empty

                    <tr>

                        <td
                            colspan="4"
                            class="px-6 py-8 text-center text-sm text-gray-500"
                        >
                            {{ __('Aucune séquence configurée.') }}
                        </td>

                    </tr>

                @endforelse

            </tbody>

        </table>

    </div>

</section>