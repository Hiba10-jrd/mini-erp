<?php

use App\Models\SupplierPayment;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

new class extends \Livewire\Volt\Component
{
    #[Locked]
    public int $supplierPaymentId;

    public function mount(int $supplierPaymentId): void
    {
        Gate::authorize('payments.view');

        $this->supplierPaymentId = $supplierPaymentId;

        SupplierPayment::query()->findOrFail($supplierPaymentId);
    }

    public function with(): array
    {
        Gate::authorize('payments.view');

        return [
            'payment' => SupplierPayment::query()
                ->with([
                    'supplier:id,code,name,trade_name',
                    'paymentMethod:id,name,payment_type',
                    'creator:id,name',
                    'allocations.supplierInvoice:id,number,supplier_invoice_number,total_ttc',
                ])
                ->findOrFail($this->supplierPaymentId),
        ];
    }
};
?>

<section class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-sm font-medium text-indigo-700">
                {{ __('Paiement fournisseur #:id', [
                    'id' => $payment->id,
                ]) }}
            </p>

            <h3 class="mt-1 text-xl font-semibold text-gray-900">
                {{ $payment->supplier->name }}
            </h3>

            @if ($payment->supplier->trade_name)
                <p class="text-sm text-gray-500">
                    {{ $payment->supplier->trade_name }}
                </p>
            @endif
        </div>

        <a
            href="{{ route('purchases.payments.index') }}"
            wire:navigate
            class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
        >
            {{ __('Retour aux paiements fournisseurs') }}
        </a>
    </div>

    @if (session('status'))
        <div class="border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    <section class="border-y border-gray-200 bg-white p-5 sm:p-6">
        <dl class="grid gap-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-gray-500">
                    {{ __('Date du paiement') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $payment->payment_date->format('d/m/Y') }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Mode de paiement') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $payment->paymentMethod->name }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Référence') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $payment->reference ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Montant total') }}
                </dt>

                <dd class="mt-1 text-lg font-semibold text-gray-900">
                    {{ str_replace('.', ',', $payment->amount) }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Fournisseur') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $payment->supplier->code }}
                    ·
                    {{ $payment->supplier->name }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Enregistré par') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $payment->creator?->name ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">
                    {{ __('Enregistré le') }}
                </dt>

                <dd class="mt-1 font-medium text-gray-900">
                    {{ $payment->created_at->format('d/m/Y H:i') }}
                </dd>
            </div>
        </dl>

        @if ($payment->details)
            <div class="mt-6 border-t border-gray-200 pt-5">
                <h4 class="font-semibold text-gray-900">
                    {{ __('Informations du règlement') }}
                </h4>

                <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    @if (filled($payment->details['cheque_number'] ?? null))
                        <div>
                            <dt class="text-gray-500">
                                {{ __('Numéro du chèque') }}
                            </dt>

                            <dd class="mt-1 font-medium">
                                {{ $payment->details['cheque_number'] }}
                            </dd>
                        </div>
                    @endif

                    @if (filled($payment->details['transaction_reference'] ?? null))
                        <div>
                            <dt class="text-gray-500">
                                {{ __('Référence transaction') }}
                            </dt>

                            <dd class="mt-1 font-medium">
                                {{ $payment->details['transaction_reference'] }}
                            </dd>
                        </div>
                    @endif

                    @if (filled($payment->details['bank_name'] ?? null))
                        <div>
                            <dt class="text-gray-500">
                                {{ __('Banque') }}
                            </dt>

                            <dd class="mt-1 font-medium">
                                {{ $payment->details['bank_name'] }}
                            </dd>
                        </div>
                    @endif

                    @if (filled($payment->details['due_date'] ?? null))
                        <div>
                            <dt class="text-gray-500">
                                {{ __('Échéance du chèque') }}
                            </dt>

                            <dd class="mt-1 font-medium">
                                {{ \Carbon\Carbon::parse(
                                    $payment->details['due_date']
                                )->format('d/m/Y') }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        @endif

        @if ($payment->notes)
            <div class="mt-6 border-t border-gray-200 pt-5">
                <h4 class="font-semibold text-gray-900">
                    {{ __('Notes') }}
                </h4>

                <p class="mt-2 whitespace-pre-line text-sm text-gray-600">
                    {{ $payment->notes }}
                </p>
            </div>
        @endif
    </section>

    <section class="overflow-hidden border-y border-gray-200 bg-white">
        <div class="border-b border-gray-200 px-5 py-4">
            <h4 class="font-semibold text-gray-900">
                {{ __('Affectations aux factures fournisseurs') }}
            </h4>
        </div>

        <div class="overflow-x-auto">
            <div class="erp-table-scroll"><table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-start text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">
                            {{ __('FAF') }}
                        </th>

                        <th class="px-4 py-3">
                            {{ __('Référence fournisseur') }}
                        </th>

                        <th class="px-4 py-3 text-end">
                            {{ __('TTC facture') }}
                        </th>

                        <th class="px-4 py-3 text-end">
                            {{ __('Affecté') }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @foreach ($payment->allocations as $allocation)
                        <tr>
                            <td class="px-4 py-4">
                                @can('purchases.view')
                                    <a
                                        href="{{ route(
                                            'purchases.invoices.show',
                                            $allocation->supplierInvoice
                                        ) }}"
                                        wire:navigate
                                        class="font-semibold text-indigo-700 hover:text-indigo-900"
                                    >
                                        {{ $allocation->supplierInvoice->number }}
                                    </a>
                                @else
                                    <span class="font-semibold">
                                        {{ $allocation->supplierInvoice->number }}
                                    </span>
                                @endcan
                            </td>

                            <td class="px-4 py-4">
                                {{ $allocation->supplierInvoice->supplier_invoice_number }}
                            </td>

                            <td class="px-4 py-4 text-end">
                                {{ str_replace(
                                    '.',
                                    ',',
                                    $allocation->supplierInvoice->total_ttc
                                ) }}
                            </td>

                            <td class="px-4 py-4 text-end font-semibold">
                                {{ str_replace('.', ',', $allocation->amount) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    </section>

    <div class="border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        {{ __('Un paiement enregistré constitue une opération financière historique. Il n’est ni modifiable ni supprimable depuis LOT 17.') }}
    </div>
</section>