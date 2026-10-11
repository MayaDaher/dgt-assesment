<?php

namespace App\Enums;

/**
 * Allowed values for ?sort= on the product listing. A leading "-" means descending.
 */
enum ProductSort: string
{
    case Newest = '-created_at';
    case Oldest = 'created_at';
    case PriceAsc = 'price';
    case PriceDesc = '-price';
    case NameAsc = 'name';
    case NameDesc = '-name';

    public const DEFAULT = self::Newest;

    public function column(): string
    {
        return ltrim($this->value, '-');
    }

    public function direction(): string
    {
        return str_starts_with($this->value, '-') ? 'desc' : 'asc';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
