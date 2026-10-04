<?php

declare(strict_types=1);

namespace App\Application\Printing;

/** Defaults injected from configuration (the application layer does not read config itself). */
final class PrintDefaults
{
    public function __construct(
        public readonly string $businessName = 'DG WAY',
        public readonly ?string $businessSubtitle = 'Supply & Distribution',
        public readonly int $receiptWidthMm = 80,
    ) {
    }

    public function settings(): PrintSettings
    {
        return PrintSettings::of(['business_name' => $this->businessName, 'business_subtitle' => $this->businessSubtitle, 'receipt_width_mm' => $this->receiptWidthMm], 1, true);
    }
}
