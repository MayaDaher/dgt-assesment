<?php

namespace App\Queries\Orders;

use App\Enums\OrderStatus;
use DateTimeInterface;

final readonly class OrderFilters
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public ?OrderStatus $status = null,
        public ?int $userId = null,
        public ?string $search = null,
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $to = null,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}
}
