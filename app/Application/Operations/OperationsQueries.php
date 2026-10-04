<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;

/**
 * Cross-context READ models for the owner (A5-B §17). Reporting only: no
 * aggregate is loaded and nothing is written. All dates are business dates
 * in the business timezone (Asia/Jakarta). No HPP/COGS is computed.
 */
interface OperationsQueries
{
    /**
     * Receivables as they stood at the END of a business date, rebuilt from
     * dated facts (confirmations, payments, payment reversals, return credits,
     * sale reversals), so a past date can be shown exactly.
     *
     * @return array{as_of: string, total: string, not_yet_due: string, due_today: string, overdue: string, open_sales: int, customers: int}
     */
    public function receivablesAsOf(string $date): array;

    /** Purchase orders confirmed and goods receipts posted on the date (quantities per UOM). */
    public function purchasing(string $date): array;

    /** Stock at the end of the date from the ledger, per UOM and bucket, plus the day's losses and active lots. */
    public function inventoryAsOf(string $date): array;

    /** @return Page<array<string, mixed>> the day's business events, newest first */
    public function activity(string $date, ?string $type, PageRequest $page): Page;

    /** @return Page<array<string, mixed>> products, partners and documents matching a term */
    public function search(string $term, string $type, PageRequest $page): Page;

    /** @return array{id: string, code: string, name: string}|null */
    public function nodeByCode(string $code): ?array;

    /** @return array{id: string, code: string, name: string}|null */
    public function partnerByCode(string $code, bool $activeOnly = true): ?array;

    /** @return list<string> configured units of measure */
    public function uomCodes(): array;

    public function operatorCount(): int;
}
