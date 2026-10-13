<?php

namespace App\Observers;

use App\Models\Promotion;
use App\Services\Promotions\PromotionAuditLogger;

class PromotionObserver
{
    public function __construct(private readonly PromotionAuditLogger $audits) {}

    public function updated(Promotion $promotion): void
    {
        $this->audits->record($promotion);
    }
}
