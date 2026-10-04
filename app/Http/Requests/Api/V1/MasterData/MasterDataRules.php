<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MasterData;

/**
 * Shape validation shared by master-data requests (types, lengths, required
 * keys). Business rules (code format, phone normalisation, walk-in must be
 * cash, …) are enforced by the domain and reported with domain error codes.
 */
final class MasterDataRules
{
    /** @return array<string, list<string>> */
    public static function address(string $prefix, bool $nullable = true): array
    {
        return [
            $prefix => [$nullable ? 'nullable' : 'required', 'array'],
            "{$prefix}.street" => ["required_with:{$prefix}", 'nullable', 'string', 'max:255'],
            "{$prefix}.village" => ['nullable', 'string', 'max:150'],
            "{$prefix}.district" => ['nullable', 'string', 'max:150'],
            "{$prefix}.city" => ['nullable', 'string', 'max:150'],
            "{$prefix}.province" => ['nullable', 'string', 'max:150'],
            "{$prefix}.postal_code" => ['nullable', 'string', 'max:10'],
            "{$prefix}.landmark" => ['nullable', 'string', 'max:255'],
            "{$prefix}.latitude" => ['nullable', 'numeric', "required_with:{$prefix}.longitude"],
            "{$prefix}.longitude" => ['nullable', 'numeric', "required_with:{$prefix}.latitude"],
        ];
    }

    /** @return list<string> */
    public static function code(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'max:40'];
    }

    /** @return list<string> */
    public static function name(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'max:150'];
    }

    /** @return list<string> */
    public static function notes(): array
    {
        return ['sometimes', 'nullable', 'string', 'max:2000'];
    }

    /** @return list<string> */
    public static function expectedVersion(): array
    {
        return ['sometimes', 'nullable', 'integer', 'min:1'];
    }

    /** @return array<string, list<string>> */
    public static function terms(string $prefix = ''): array
    {
        return [
            "{$prefix}term_days" => ['sometimes', 'integer', 'min:0', 'max:365'],
            "{$prefix}credit_limit" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}notes" => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
