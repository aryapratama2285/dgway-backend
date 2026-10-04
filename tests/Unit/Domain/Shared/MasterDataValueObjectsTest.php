<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Address\Coordinates;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Contact\EmailAddress;
use App\Domain\Shared\Contact\PhoneNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;
use App\Domain\Shared\Text\Notes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MasterDataValueObjectsTest extends TestCase
{
    public function test_business_code_is_normalised_to_upper_case(): void
    {
        $this->assertSame('TLR-A-KG', BusinessCode::of(' tlr-a-kg ')->value);
        $this->assertSame('DG-WAY-PRIMARY', BusinessCode::of('dg-way-primary')->value);
        $this->assertSame('COOKING_OIL', BusinessCode::of('cooking_oil')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function badCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'space inside' => ['TLR A'];
        yield 'starts with dash' => ['-TLR'];
        yield 'slash' => ['TLR/A'];
        yield 'too long' => [str_repeat('A', 41)];
    }

    #[DataProvider('badCodes')]
    public function test_invalid_codes_rejected(string $code): void
    {
        $this->expectException(InvariantViolation::class);
        BusinessCode::of($code, 'SKU');
    }

    public function test_name_collapses_whitespace_and_is_required(): void
    {
        $this->assertSame('Telur Ayam Grade A', Name::of("  Telur   Ayam\tGrade A ")->value);

        $this->expectException(InvariantViolation::class);
        Name::of('   ');
    }

    public function test_name_length_limit(): void
    {
        $this->expectException(InvariantViolation::class);
        Name::of(str_repeat('x', 151));
    }

    /** @return iterable<string, array{string, string}> */
    public static function phones(): iterable
    {
        yield 'local mobile with dashes' => ['0812-3456-7890', '+6281234567890'];
        yield '62 prefix with spaces' => ['62 812 3456 7890', '+6281234567890'];
        yield 'e164' => ['+6281234567890', '+6281234567890'];
        yield 'landline with area code' => ['(021) 555-1234', '+62215551234'];
    }

    #[DataProvider('phones')]
    public function test_phone_numbers_are_normalised_to_e164(string $raw, string $expected): void
    {
        $phone = PhoneNumber::of($raw);
        $this->assertSame($expected, $phone->e164);
    }

    public function test_same_phone_typed_differently_is_equal(): void
    {
        $this->assertTrue(PhoneNumber::of('081234567890')->equals(PhoneNumber::of('+62 812-3456-7890')));
        $this->assertSame('081234567890', PhoneNumber::of('+6281234567890')->local());
    }

    public function test_invalid_phone_rejected(): void
    {
        $this->expectException(InvariantViolation::class);
        PhoneNumber::of('12ab');
    }

    public function test_email_lowercased_and_validated(): void
    {
        $this->assertSame('budi@warung.id', EmailAddress::of(' Budi@Warung.ID ')->value);
        $this->expectException(InvariantViolation::class);
        EmailAddress::of('budi@');
    }

    public function test_address_only_requires_street(): void
    {
        $a = Address::of('Jl. Raya Deltamas No. 5', landmark: 'depan masjid');

        $this->assertSame('Jl. Raya Deltamas No. 5', $a->street);
        $this->assertNull($a->city);
        $this->assertNull($a->coordinates);
        $this->assertSame('depan masjid', $a->landmark);
    }

    public function test_full_indonesian_address_round_trip(): void
    {
        $a = Address::fromArray([
            'street' => 'Jl. Kenari 12', 'village' => 'Sukamahi', 'district' => 'Cikarang Pusat',
            'city' => 'Kabupaten Bekasi', 'province' => 'Jawa Barat', 'postal_code' => '17530',
            'latitude' => '-6.3591', 'longitude' => '107.1724',
        ]);

        $this->assertSame('17530', $a->postalCode);
        $this->assertSame('-6.3591000', $a->coordinates?->latitude);
        $this->assertTrue($a->equals(Address::fromArray($a->jsonSerialize())));
    }

    public function test_address_street_required(): void
    {
        $this->expectException(InvariantViolation::class);
        Address::of('  ');
    }

    public function test_postal_code_must_be_five_digits(): void
    {
        $this->expectException(InvariantViolation::class);
        Address::of('Jl. X', postalCode: '1753');
    }

    public function test_coordinates_range_checked(): void
    {
        $this->expectException(InvariantViolation::class);
        Coordinates::of('-91', '107');
    }

    public function test_id_accepts_uuid_and_rejects_other(): void
    {
        $this->assertSame('01928a3c-0000-7000-8000-000000000001', Id::fromString('01928A3C-0000-7000-8000-000000000001')->value);
        $this->expectException(InvariantViolation::class);
        Id::fromString('42');
    }

    public function test_notes_empty_becomes_null(): void
    {
        $this->assertNull(Notes::clean('   '));
        $this->assertSame('bayar tiap Jumat', Notes::clean(' bayar tiap Jumat '));
    }
}
