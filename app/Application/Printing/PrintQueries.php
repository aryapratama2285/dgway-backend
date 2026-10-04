<?php

declare(strict_types=1);

namespace App\Application\Printing;

/**
 * Read port for printable documents (raw rows; formatting happens in
 * PrintDocuments). Every method throws EntityNotFound for an unknown id.
 */
interface PrintQueries
{
    /** @return array{sale: object, lines: list<object>, payments: list<object>, returns: list<object>} */
    public function sale(string $saleId): array;

    /** @return array{order: object, lines: list<object>} */
    public function purchaseOrder(string $orderId): array;

    /** @return array{receipt: object, lines: list<object>, dispositions: list<object>, lots: list<object>} */
    public function goodsReceipt(string $receiptId): array;

    /** Lot + product + supplier + GR + parent lot; id or lot number. */
    public function lot(string $lotIdOrNumber): object;

    /** @return array{type: string, id: string}|null the entity of that type with this id, if it exists */
    public function existsById(string $type, string $id): ?array;

    /** @return list<array{type: string, id: string}> text matches: lot number, product SKU (exact, upper-case) */
    public function matchText(string $text): array;

    /** Short typed display block for a resolved entity. */
    public function display(string $type, string $id): array;
}
