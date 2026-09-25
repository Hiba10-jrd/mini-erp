<?php

namespace App\Services;

use App\Models\CommercialSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CommercialSettingsService
{
    /** @param array{currency_code: string, currency_name: string} $attributes */
    public function save(array $attributes): CommercialSetting
    {
        Gate::authorize('company.administer');

        return DB::transaction(function () use ($attributes): CommercialSetting {
            $setting = CommercialSetting::query()
                ->where('singleton', true)
                ->lockForUpdate()
                ->first() ?? new CommercialSetting(['singleton' => true]);

            $setting->fill($attributes)->save();

            return $setting->fresh();
        });
    }
}
