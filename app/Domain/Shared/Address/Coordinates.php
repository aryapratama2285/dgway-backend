<?php

declare(strict_types=1);

namespace App\Domain\Shared\Address;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Math\Decimal;
use JsonSerializable;

/**
 * Optional WGS-84 coordinates (7 decimals ≈ 1 cm). Kept as decimals, not
 * floats, and stored as NUMERIC(10,7). No GIS behaviour in the MVP; the point
 * exists so route-density analysis can use it later.
 */
final class Coordinates implements JsonSerializable
{
    public const SCALE = 7;

    private function __construct(
        public readonly string $latitude,
        public readonly string $longitude,
    ) {
    }

    public static function of(string $latitude, string $longitude): self
    {
        $lat = Decimal::of($latitude);
        $lng = Decimal::of($longitude);

        if ($lat->isLessThan(Decimal::of('-90')) || $lat->isGreaterThan(Decimal::of('90'))) {
            throw InvariantViolation::because('LATITUDE_INVALID', 'Latitude must be between -90 and 90.', ['field' => 'latitude']);
        }
        if ($lng->isLessThan(Decimal::of('-180')) || $lng->isGreaterThan(Decimal::of('180'))) {
            throw InvariantViolation::because('LONGITUDE_INVALID', 'Longitude must be between -180 and 180.', ['field' => 'longitude']);
        }

        return new self($lat->toFixed(self::SCALE), $lng->toFixed(self::SCALE));
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude && $this->longitude === $other->longitude;
    }

    /** @return array{latitude: string, longitude: string} */
    public function jsonSerialize(): array
    {
        return ['latitude' => $this->latitude, 'longitude' => $this->longitude];
    }
}
