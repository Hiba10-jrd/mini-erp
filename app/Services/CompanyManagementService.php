<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CompanyManagementService
{
    /**
     * @param  array<string, string|null>  $attributes
     */
    public function save(array $attributes, ?UploadedFile $logo = null): Company
    {
        $newLogoPath = null;
        $oldLogoPath = null;

        try {
            $company = DB::transaction(function () use ($attributes, $logo, &$newLogoPath, &$oldLogoPath): Company {
                $company = Company::query()
                    ->where('singleton', true)
                    ->lockForUpdate()
                    ->first();

                if ($company === null) {
                    $company = new Company(['singleton' => true]);
                } else {
                    $oldLogoPath = $company->logo_path;
                }

                $company->fill($attributes);

                if ($logo !== null) {
                    $newLogoPath = $logo->store('companies/logos', 'public');

                    if (! is_string($newLogoPath)) {
                        throw new RuntimeException('Le logo de l’entreprise n’a pas pu être enregistré.');
                    }

                    $company->logo_path = $newLogoPath;
                }

                $company->save();

                return $company->fresh();
            });
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                Storage::disk('public')->delete($newLogoPath);
            }

            throw $exception;
        }

        if ($newLogoPath !== null && $oldLogoPath !== null && $newLogoPath !== $oldLogoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        return $company;
    }
}
