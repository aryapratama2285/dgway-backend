<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Address\Coordinates;

/** Maps the Address value object to/from the embedded address_* columns. */
final class AddressColumns
{
    /** @return array<string, string|null> */
    public static function toRow(?Address $a, string $p = 'address_'): array
    {
        return [
            $p.'street' => $a?->street,
            $p.'village' => $a?->village,
            $p.'district' => $a?->district,
            $p.'city' => $a?->city,
            $p.'province' => $a?->province,
            $p.'postal_code' => $a?->postalCode,
            $p.'landmark' => $a?->landmark,
            $p.'latitude' => $a?->coordinates?->latitude,
            $p.'longitude' => $a?->coordinates?->longitude,
        ];
    }

    public static function fromRow(object $row, string $p = 'address_'): ?Address
    {
        if ($row->{$p.'street'} === null) {
            return null;
        }

        return Address::of(
            $row->{$p.'street'},
            $row->{$p.'village'},
            $row->{$p.'district'},
            $row->{$p.'city'},
            $row->{$p.'province'},
            $row->{$p.'postal_code'},
            $row->{$p.'landmark'},
            $row->{$p.'latitude'} === null ? null : Coordinates::of((string) $row->{$p.'latitude'}, (string) $row->{$p.'longitude'}),
        );
    }
}
