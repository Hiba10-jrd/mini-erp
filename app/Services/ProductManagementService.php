<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductManagementService
{
    /** @param array<string, mixed> $attributes */
    public function save(?int $productId, array $attributes, ?UploadedFile $image = null): Product
    {
        Gate::authorize('stock.manage');

        if (($attributes['type'] ?? null) === 'service') {
            $attributes['minimum_stock'] = null;
            $attributes['maximum_stock'] = null;
        }

        $newImagePath = null;
        $oldImagePath = null;

        try {
            $product = DB::transaction(function () use ($productId, $attributes, $image, &$newImagePath, &$oldImagePath): Product {
                $product = $productId === null
                    ? new Product
                    : Product::query()->lockForUpdate()->findOrFail($productId);

                if ($product->exists) {
                    $oldImagePath = $product->image_path;
                } else {
                    $product->is_active = true;
                }

                $product->fill($attributes)->save();

                if ($image !== null) {
                    $newImagePath = $image->store("products/{$product->id}/images", 'public');

                    if (! is_string($newImagePath)) {
                        throw new RuntimeException('L’image du produit n’a pas pu être enregistrée.');
                    }

                    $product->image_path = $newImagePath;
                    $product->save();
                }

                return $product->load(['category', 'unit', 'taxRate']);
            });
        } catch (Throwable $exception) {
            if ($newImagePath !== null) {
                Storage::disk('public')->delete($newImagePath);
            }

            throw $exception;
        }

        if ($newImagePath !== null && $oldImagePath !== null && $this->belongsToProduct($oldImagePath, $product->id)) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return $product;
    }

    public function setActive(int $productId, bool $isActive): Product
    {
        Gate::authorize('stock.manage');

        return DB::transaction(function () use ($productId, $isActive): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $product->forceFill(['is_active' => $isActive])->save();

            return $product->fresh(['category', 'unit', 'taxRate']);
        });
    }

    private function belongsToProduct(string $path, int $productId): bool
    {
        return str_starts_with(str_replace('\\', '/', $path), "products/{$productId}/");
    }
}
