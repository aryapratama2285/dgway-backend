<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;

/**
 * The business document that caused a movement (goods receipt, inventory
 * operation, later: sale, transfer). Every movement has one: stock never
 * changes "just because".
 */
final class SourceDocument
{
    public function __construct(
        public readonly string $type,
        public readonly Id $id,
        public readonly ?Id $lineId = null,
    ) {
        if (preg_match('/^[a-z][a-z_]{1,39}$/', $type) !== 1) {
            throw InvariantViolation::because('SOURCE_DOCUMENT_TYPE_INVALID', "Invalid source document type '{$type}'.");
        }
    }
}
