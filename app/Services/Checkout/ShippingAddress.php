<?php

namespace App\Services\Checkout;

/**
 * Fulfilment address captured at checkout and stored on the order.
 * Later profile edits must not change a placed order.
 */
final readonly class ShippingAddress
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $addressLine1,
        public ?string $addressLine2,
        public string $city,
        public string $state,
        public ?string $postalCode,
        public string $country,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            name: trim((string) $payload['name']),
            phone: trim((string) $payload['phone']),
            addressLine1: trim((string) $payload['address_line1']),
            addressLine2: self::optional($payload['address_line2'] ?? null),
            city: trim((string) $payload['city']),
            state: trim((string) $payload['state']),
            postalCode: self::optional($payload['postal_code'] ?? null),
            country: trim((string) $payload['country']),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toOrderAttributes(): array
    {
        return [
            'shipping_name' => $this->name,
            'shipping_phone' => $this->phone,
            'shipping_address_line1' => $this->addressLine1,
            'shipping_address_line2' => $this->addressLine2,
            'shipping_city' => $this->city,
            'shipping_state' => $this->state,
            'shipping_postal_code' => $this->postalCode,
            'shipping_country' => $this->country,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ];
    }

    private static function optional(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
