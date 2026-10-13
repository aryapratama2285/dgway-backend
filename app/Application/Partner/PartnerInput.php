<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Domain\Partner\CustomerRole;
use App\Domain\Partner\CustomerType;
use App\Domain\Partner\PaymentTerms;
use App\Domain\Partner\SupplierRole;
use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Contact\EmailAddress;
use App\Domain\Shared\Contact\PhoneNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Text\Name;

/**
 * Translates validated primitive input into partner value objects.
 * (Application-layer mapping; the rules themselves live in the domain types.)
 */
final class PartnerInput
{
    public static function phone(?string $raw): ?PhoneNumber
    {
        return $raw === null || trim($raw) === '' ? null : PhoneNumber::of($raw);
    }

    public static function email(?string $raw): ?EmailAddress
    {
        return $raw === null || trim($raw) === '' ? null : EmailAddress::of($raw);
    }

    /** @param array<string, mixed>|null $data */
    public static function address(?array $data): ?Address
    {
        return $data === null ? null : Address::fromArray($data);
    }

    public static function legalName(?string $raw): ?Name
    {
        return $raw === null || trim($raw) === '' ? null : Name::of($raw, 'Legal name');
    }

    /** @param array<string, mixed> $data {term_days?, credit_limit?} */
    public static function terms(array $data, ?PaymentTerms $current = null): PaymentTerms
    {
        $termDays = array_key_exists('term_days', $data) ? (int) $data['term_days'] : ($current?->termDays ?? 0);
        $limit = array_key_exists('credit_limit', $data)
            ? ($data['credit_limit'] === null ? null : Money::of((string) $data['credit_limit']))
            : $current?->creditLimit;

        return PaymentTerms::of($termDays, $limit);
    }

    /** @param array<string, mixed> $data {term_days?, notes?} */
    public static function supplierRole(array $data): SupplierRole
    {
        return SupplierRole::start(self::terms($data), $data['notes'] ?? null);
    }

    /** @param array<string, mixed> $data {customer_type, term_days?, credit_limit?, notes?} */
    public static function customerRole(array $data): CustomerRole
    {
        return CustomerRole::start(self::customerType($data['customer_type'] ?? null), self::terms($data), $data['notes'] ?? null);
    }

    /**
     * F5A: true when customer terms extend credit (a payment term over 0 days
     * or any credit limit). Granting or changing that needs
     * Permission::MANAGE_CUSTOMER_CREDIT; a cash customer does not.
     */
    public static function grantsCredit(PaymentTerms $terms): bool
    {
        return $terms->termDays > 0 || $terms->creditLimit !== null;
    }

    public static function customerType(mixed $raw): CustomerType
    {
        return CustomerType::tryFrom(strtoupper((string) $raw))
            ?? throw InvariantViolation::because('CUSTOMER_TYPE_INVALID', "Unknown customer type '{$raw}'.", ['field' => 'customer_type']);
    }
}
