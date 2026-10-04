<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;

/**
 * Read side for the Owner Operating App. Everything is paged or aggregated
 * in SQL; nothing loads a whole table into memory.
 */
interface SalesQueries
{
    /** @param array<string, mixed> $filters @return Page<array<string, mixed>> */
    public function sales(array $filters, PageRequest $page): Page;

    /** @param array<string, mixed> $filters @return Page<array<string, mixed>> */
    public function payments(array $filters, PageRequest $page): Page;

    /** @param array<string, mixed> $filters @return Page<array<string, mixed>> */
    public function customerReturns(array $filters, PageRequest $page): Page;

    /** Open sales (outstanding > 0). @param array<string, mixed> $filters @return Page<array<string, mixed>> */
    public function receivablesBySale(array $filters, string $today, PageRequest $page): Page;

    /** Who owes how much. @param array<string, mixed> $filters @return Page<array<string, mixed>> */
    public function receivablesByCustomer(array $filters, string $today, PageRequest $page): Page;

    /** @return array<string, mixed> total / not yet due / due today / overdue */
    public function receivableSummary(string $today): array;

    /** @return array<string, mixed> sales, payments, refunds and receivables for a period */
    public function salesSummary(string $dateFrom, string $dateTo, string $today): array;

    /** @return list<array<string, mixed>> quantity sold, revenue and returns per product */
    public function salesByProduct(string $dateFrom, string $dateTo): array;

    /** Display labels (code, name) for a customer, null for walk-in. @return array<string, mixed>|null */
    public function customerLabel(?string $customerId): ?array;
}
