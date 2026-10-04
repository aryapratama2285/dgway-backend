<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Printing;

use App\Application\Printing\PrintDocuments;
use App\Application\Printing\PrintSettings;
use App\Application\Printing\PrintSettingsService;
use App\Application\Printing\ScanResolver;
use App\Domain\Shared\Identity\Actor;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A5-C printing & scanning. Every GET is read-only (no Idempotency-Key, no
 * side effect, no print history). The backend returns device-neutral print
 * DATA; rendering, ESC/POS, Bluetooth and camera belong to the app.
 */
final class PrintController extends ApiController
{
    public function settings(PrintSettingsService $settings): JsonResponse
    {
        return $this->single(self::presentSettings($settings->current()));
    }

    public function updateSettings(Request $request, PrintSettingsService $settings): JsonResponse
    {
        $v = $request->validate([
            'business_name' => ['sometimes', 'required', 'string', 'max:60'],
            'business_subtitle' => ['sometimes', 'nullable', 'string', 'max:60'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:200'],
            'receipt_width_mm' => ['sometimes', 'required', 'integer', Rule::in(PrintSettings::WIDTHS)],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $expected = $v['expected_version'] ?? null;
        unset($v['expected_version']);

        return $this->command($request, 'print_settings.update', fn (Actor $a) => (object) ['settings' => $settings->update($a, $v, $expected), 'id' => null],
            fn (object $r) => self::presentSettings($r->settings), 200, 'print_settings');
    }

    public function receipt(Request $request, string $id, PrintDocuments $docs): JsonResponse
    {
        $v = $request->validate(['width' => ['nullable', 'integer', Rule::in(PrintSettings::WIDTHS)]]);

        return $this->single($docs->receipt($id, isset($v['width']) ? (int) $v['width'] : null));
    }

    public function purchaseOrder(string $id, PrintDocuments $docs): JsonResponse
    {
        return $this->single($docs->purchaseOrder($id));
    }

    public function goodsReceipt(string $id, PrintDocuments $docs): JsonResponse
    {
        return $this->single($docs->goodsReceipt($id));
    }

    public function lotLabel(string $lot, PrintDocuments $docs): JsonResponse
    {
        return $this->single($docs->lotLabel($lot));
    }

    public function resolve(Request $request, ScanResolver $resolver): JsonResponse
    {
        $v = $request->validate(['code' => ['required', 'string', 'max:512']]);

        return $this->single($resolver->resolve($v['code']));
    }

    private static function presentSettings(PrintSettings $s): array
    {
        return $s->toInput() + [
            'supported_receipt_widths_mm' => PrintSettings::WIDTHS,
            'version' => $s->version,
            'is_default' => $s->isDefault,
        ];
    }
}
