<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Catalog\ProductAuditLogger;

class ProductObserver
{
    public function __construct(private readonly ProductAuditLogger $audits) {}

    public function updated(Product $product): void
    {
        $this->audits->record($product);
    }
}
