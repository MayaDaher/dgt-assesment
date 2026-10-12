<?php

namespace App\Support;

use App\Exceptions\Cart\InvalidCartTokenException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CartToken
{
    public static function header(): string
    {
        return (string) config('cart.token_header', 'X-Cart-Token');
    }

    /**
     * @throws InvalidCartTokenException
     */
    public static function from(Request $request): ?string
    {
        $value = $request->headers->get(self::header());

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (! Str::isUuid($value)) {
            throw new InvalidCartTokenException;
        }

        return $value;
    }
}
