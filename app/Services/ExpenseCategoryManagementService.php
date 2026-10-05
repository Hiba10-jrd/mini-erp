<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryManagementService
{
    public function create(array $attributes): ExpenseCategory
    {
        Gate::authorize('payments.create');

        return DB::transaction(function () use ($attributes): ExpenseCategory {
            $name = $this->requiredString(
                $attributes['name'] ?? null,
                'name',
                __('Le nom de la catégorie est obligatoire.')
            );

            $this->ensureNameIsUnique($name);

            return ExpenseCategory::query()->create([
                'name' => $name,
                'description' => $this->nullableString(
                    $attributes['description'] ?? null
                ),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ]);
        }, 3);
    }

    public function update(
        ExpenseCategory $category,
        array $attributes
    ): ExpenseCategory {
        Gate::authorize('payments.create');

        return DB::transaction(function () use (
            $category,
            $attributes
        ): ExpenseCategory {
            $category = ExpenseCategory::query()
                ->lockForUpdate()
                ->findOrFail($category->id);

            $name = $this->requiredString(
                $attributes['name'] ?? null,
                'name',
                __('Le nom de la catégorie est obligatoire.')
            );

            $this->ensureNameIsUnique($name, $category->id);

            $category->update([
                'name' => $name,
                'description' => $this->nullableString(
                    $attributes['description'] ?? null
                ),
                'is_active' => (bool) (
                    $attributes['is_active']
                    ?? $category->is_active
                ),
            ]);

            return $category->fresh();
        }, 3);
    }

    public function setActive(
        ExpenseCategory $category,
        bool $active
    ): ExpenseCategory {
        Gate::authorize('payments.create');

        return DB::transaction(function () use (
            $category,
            $active
        ): ExpenseCategory {
            $category = ExpenseCategory::query()
                ->lockForUpdate()
                ->findOrFail($category->id);

            $category->update([
                'is_active' => $active,
            ]);

            return $category->fresh();
        }, 3);
    }

    private function ensureNameIsUnique(
        string $name,
        ?int $exceptId = null
    ): void {
        $query = ExpenseCategory::query()
            ->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => __('Cette catégorie de dépenses existe déjà.'),
            ]);
        }
    }

    private function requiredString(
        mixed $value,
        string $field,
        string $message
    ): string {
        $value = trim((string) $value);

        if ($value === '') {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
