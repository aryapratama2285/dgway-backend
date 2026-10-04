<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Domain\Shared\Exception\InvariantViolation;

/**
 * Business identity printed on every document (A5-C, decision D11).
 * Configuration, not a business domain: validated here, stored by
 * PrintSettingsStore, defaults from configuration until the owner saves.
 */
final class PrintSettings
{
    public const WIDTHS = [58, 80];

    private const LIMITS = ['business_name' => 60, 'business_subtitle' => 60, 'address' => 200, 'phone' => 30, 'footer_text' => 200];

    private function __construct(
        public readonly string $businessName,
        public readonly ?string $businessSubtitle,
        public readonly ?string $address,
        public readonly ?string $phone,
        public readonly ?string $footerText,
        public readonly int $receiptWidthMm,
        public readonly int $version,
        public readonly bool $isDefault,
    ) {
    }

    /** @param array<string, mixed> $in */
    public static function of(array $in, int $version = 1, bool $isDefault = false): self
    {
        $text = function (string $field, bool $required = false) use ($in): ?string {
            $v = isset($in[$field]) ? trim(preg_replace('/\s+/u', ' ', (string) $in[$field]) ?? '') : '';
            if ($v === '') {
                if ($required) {
                    throw InvariantViolation::because('BUSINESS_NAME_REQUIRED', 'The business name printed on documents cannot be empty.', ['field' => $field]);
                }

                return null;
            }
            if (mb_strlen($v) > self::LIMITS[$field]) {
                throw InvariantViolation::because('PRINT_FIELD_TOO_LONG', "{$field} is longer than ".self::LIMITS[$field].' characters.', ['field' => $field, 'max' => self::LIMITS[$field]]);
            }

            return $v;
        };
        $width = (int) ($in['receipt_width_mm'] ?? 80);
        if (! in_array($width, self::WIDTHS, true)) {
            throw InvariantViolation::because('RECEIPT_WIDTH_INVALID', 'Receipt width must be 58 or 80 mm.', ['field' => 'receipt_width_mm', 'allowed' => self::WIDTHS]);
        }

        return new self($text('business_name', true), $text('business_subtitle'), $text('address'), $text('phone'), $text('footer_text'), $width, $version, $isDefault);
    }

    /** Changed fields applied on top of the current settings (absent key = unchanged, null = clear). */
    public function with(array $changes): self
    {
        return self::of(array_merge($this->toInput(), $changes), $this->version + 1);
    }

    /** @return array<string, mixed> */
    public function toInput(): array
    {
        return [
            'business_name' => $this->businessName, 'business_subtitle' => $this->businessSubtitle, 'address' => $this->address,
            'phone' => $this->phone, 'footer_text' => $this->footerText, 'receipt_width_mm' => $this->receiptWidthMm,
        ];
    }

    /** The business block of every print document. */
    public function business(): array
    {
        return array_intersect_key($this->toInput(), array_flip(['business_name', 'business_subtitle', 'address', 'phone', 'footer_text']));
    }
}
