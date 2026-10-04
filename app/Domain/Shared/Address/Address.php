<?php

declare(strict_types=1);

namespace App\Domain\Shared\Address;

use App\Domain\Shared\Exception\InvariantViolation;
use JsonSerializable;

/**
 * Indonesian operational address as one value object, reused by business
 * partners and locations (and later by delivery addresses).
 *
 * Only `street` is required: a small warung may only have
 * "Jl. Raya Deltamas No. 5, depan masjid". Administrative levels
 * (kelurahan/desa, kecamatan, kota/kabupaten, provinsi) and the postal code
 * are optional but structured, so they can be grouped and filtered later
 * (route density by kecamatan) without parsing free text.
 */
final class Address implements JsonSerializable
{
    private const MAX = 150;

    private function __construct(
        public readonly string $street,
        public readonly ?string $village,
        public readonly ?string $district,
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly ?string $postalCode,
        public readonly ?string $landmark,
        public readonly ?Coordinates $coordinates,
    ) {
    }

    public static function of(
        string $street,
        ?string $village = null,
        ?string $district = null,
        ?string $city = null,
        ?string $province = null,
        ?string $postalCode = null,
        ?string $landmark = null,
        ?Coordinates $coordinates = null,
    ): self {
        $street = self::clean($street, 'street', 255);
        if ($street === null) {
            throw InvariantViolation::because('ADDRESS_STREET_REQUIRED', 'Address street is required.', ['field' => 'street']);
        }

        $postal = self::clean($postalCode, 'postal_code', 10);
        if ($postal !== null && preg_match('/^\d{5}$/', $postal) !== 1) {
            throw InvariantViolation::because('POSTAL_CODE_INVALID', 'Indonesian postal code must be 5 digits.', ['field' => 'postal_code']);
        }

        return new self(
            $street,
            self::clean($village, 'village'),
            self::clean($district, 'district'),
            self::clean($city, 'city'),
            self::clean($province, 'province'),
            $postal,
            self::clean($landmark, 'landmark', 255),
            $coordinates,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $coordinates = isset($data['latitude'], $data['longitude']) && $data['latitude'] !== null && $data['longitude'] !== null
            ? Coordinates::of((string) $data['latitude'], (string) $data['longitude'])
            : null;

        return self::of(
            (string) ($data['street'] ?? ''),
            $data['village'] ?? null,
            $data['district'] ?? null,
            $data['city'] ?? null,
            $data['province'] ?? null,
            isset($data['postal_code']) ? (string) $data['postal_code'] : null,
            $data['landmark'] ?? null,
            $coordinates,
        );
    }

    public function equals(self $other): bool
    {
        return $this->jsonSerialize() === $other->jsonSerialize();
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return [
            'street' => $this->street,
            'village' => $this->village,
            'district' => $this->district,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postalCode,
            'landmark' => $this->landmark,
            'latitude' => $this->coordinates?->latitude,
            'longitude' => $this->coordinates?->longitude,
        ];
    }

    private static function clean(?string $value, string $field, int $max = self::MAX): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw InvariantViolation::because('ADDRESS_FIELD_TOO_LONG', "Address {$field} must be at most {$max} characters.", ['field' => $field]);
        }

        return $value;
    }
}
