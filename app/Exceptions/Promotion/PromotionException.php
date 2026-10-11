<?php

namespace App\Exceptions\Promotion;

use App\Enums\PromotionError;
use App\Exceptions\ApiException;

class PromotionException extends ApiException
{
    protected int $status = 422;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly PromotionError $reason,
        array $details = [],
        ?string $message = null,
    ) {
        $this->errorCode = $reason->value;

        parent::__construct($message ?? $reason->message(), $details);
    }
}
