<?php

namespace App\Services;

use App\Models\CustomerReminder;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerReminderManagementService
{
    public function __construct(private PaymentManagementService $payments) {}

    /** @param array{reminder_date: string, channel: string, note?: ?string} $attributes */
    public function create(Invoice $invoice, array $attributes): CustomerReminder
    {
        Gate::authorize('payments.create');

        return DB::transaction(function () use ($invoice, $attributes): CustomerReminder {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $lockedInvoice->isIssued()) {
                throw ValidationException::withMessages([
                    'invoice_id' => __('Seule une facture émise peut recevoir une relance.'),
                ]);
            }

            $validated = Validator::make($attributes, [
                'reminder_date' => ['required', 'date_format:Y-m-d'],
                'channel' => ['required', 'string', Rule::in(CustomerReminder::channels())],
                'note' => ['nullable', 'string'],
            ])->validate();

            if (bccomp($this->payments->remainingAmount($lockedInvoice), '0.00', 2) === 0) {
                throw ValidationException::withMessages([
                    'invoice_id' => __('Une facture soldée ne peut pas recevoir une nouvelle relance.'),
                ]);
            }

            $note = trim((string) ($validated['note'] ?? ''));

            $reminder = $lockedInvoice->reminders()->create([
                'reminder_date' => $validated['reminder_date'],
                'channel' => $validated['channel'],
                'note' => $note === '' ? null : $note,
                'created_by' => Auth::id(),
            ]);
            DB::afterCommit(function () use ($reminder, $lockedInvoice): void {
                try {
                    app(InternalNotificationDispatcher::class)->group('finance', [
                        'type' => 'reminder.created',
                        'title' => 'Relance enregistrée',
                        'title_key' => 'Relance enregistrée',
                        'message' => 'Une relance a été enregistrée pour '.$lockedInvoice->number.'.',
                        'message_key' => 'Une relance a été enregistrée pour :number.',
                        'params' => ['number' => $lockedInvoice->number],
                        'url' => route('finance.receivables.index', ['invoice' => $lockedInvoice->id], false),
                        'entity_type' => 'invoice',
                        'entity_id' => $lockedInvoice->id,
                        'severity' => 'info',
                    ], 'reminder:'.$reminder->id);
                } catch (\Throwable $e) {
                    Log::error('Reminder notification failed.', ['reminder_id' => $reminder->id, 'exception' => $e::class]);
                }
            });

            return $reminder;
        }, 3);
    }

    public function overdueDays(Invoice $invoice): int
    {
        if (! $invoice->isIssued() || $invoice->due_date === null) {
            return 0;
        }

        $today = today();

        if ($invoice->due_date->gte($today)
            || bccomp($this->payments->remainingAmount($invoice), '0.00', 2) === 0) {
            return 0;
        }

        return (int) Carbon::parse($invoice->due_date)->startOfDay()->diffInDays($today);
    }
}
