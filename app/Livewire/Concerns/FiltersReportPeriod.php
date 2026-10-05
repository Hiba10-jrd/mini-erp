<?php

namespace App\Livewire\Concerns;

use App\Services\ReportService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

trait FiltersReportPeriod
{
    public string $from = '';

    public string $to = '';

    #[Locked]
    public string $appliedFrom = '';

    #[Locked]
    public string $appliedTo = '';

    public function mount(): void
    {
        Gate::authorize('reports.view');
        $this->preset('month');
    }

    public function preset(string $preset): void
    {
        Gate::authorize('reports.view');
        [$from, $to] = match ($preset) {
            'today' => [today(), today()],
            'month' => [today()->startOfMonth(), today()->endOfMonth()],
            'previous' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
            'year' => [today()->startOfYear(), today()->endOfYear()],
            default => abort(422),
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->applyPeriod();
    }

    public function applyPeriod(): void
    {
        Gate::authorize('reports.view');
        app(ReportService::class)->period($this->from, $this->to);
        $this->appliedFrom = $this->from;
        $this->appliedTo = $this->to;
        $this->resetValidation();
        if (method_exists($this, 'resetPage')) {
            foreach (['page', 'creditsPage', 'supplierPage', 'movementsPage'] as $page) {
                $this->resetPage($page);
            }
        }
    }
}
