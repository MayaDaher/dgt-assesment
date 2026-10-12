<?php

namespace App\Services\Catalog;

use App\Enums\ProductAuditField;
use App\Enums\ProductAuditSource;
use App\Models\Product;
use App\Models\ProductAudit;
use App\Models\User;

/**
 * Writes an immutable row for each actual change to price or stock_quantity.
 *
 * Checkout and cancel mutate stock with query increment/decrement, which does
 * not fire Eloquent `updated`, so those operational movements stay off this log.
 */
class ProductAuditLogger
{
    public function record(Product $product): void
    {
        $actor = auth()->user();

        foreach (ProductAuditField::cases() as $field) {
            $change = $this->change($product, $field);

            if ($change === null) {
                continue;
            }

            ProductAudit::query()->create([
                'product_id' => $product->id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'user_id' => $actor?->id,
                'field' => $field,
                'old_value' => $change[0],
                'new_value' => $change[1],
                'source' => $actor instanceof User && $actor->isAdmin()
                    ? ProductAuditSource::Admin
                    : ProductAuditSource::System,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function change(Product $product, ProductAuditField $field): ?array
    {
        if (! $product->wasChanged($field->value)) {
            return null;
        }

        $old = $this->normalize($field, $product->getOriginal($field->value));
        $new = $this->normalize($field, $product->getAttribute($field->value));

        if ($old === $new) {
            return null;
        }

        return [$old, $new];
    }

    private function normalize(ProductAuditField $field, mixed $value): string
    {
        return match ($field) {
            ProductAuditField::Price => number_format((float) $value, 2, '.', ''),
            ProductAuditField::StockQuantity => (string) (int) $value,
        };
    }
}
