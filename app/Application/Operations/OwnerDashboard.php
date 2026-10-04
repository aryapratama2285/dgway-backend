<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Sales\SalesQueries;

/**
 * One business date at a glance for the owner: sales, money received per
 * method, receivables (as of that date), purchasing, receiving and stock.
 * No HPP / margin (out of scope until a costing policy is approved).
 */
final class OwnerDashboard
{
    public function __construct(private readonly SalesQueries $sales, private readonly OperationsQueries $ops)
    {
    }

    /** @return array<string, mixed> */
    public function forDate(string $date, string $timezone, string $currency): array
    {
        $s = $this->sales->salesSummary($date, $date, $date);
        $byMethod = [];
        foreach ($s['payments']['by_method'] as $method => $m) {
            $byMethod[strtolower($method)] = $m;
        }

        return [
            'business_date' => $date,
            'timezone' => $timezone,
            'currency' => $currency,
            'sales' => [
                'count' => $s['sales']['count'],
                'total' => $s['sales']['grand_total'],
                'walk_in_count' => $s['sales']['walk_in_count'],
                'returns_value' => $s['sales']['returns_value'],
                'net_sales' => $s['sales']['net_sales'],
                'reversed_count' => $s['sales']['reversed_count'],
            ],
            'payments' => [
                'count' => $s['payments']['count'],
                'total' => $s['payments']['received'],
                'reversed' => $s['payments']['reversed'],
                'refunded' => $s['payments']['refunded'],
                'net_received' => $s['payments']['net_received'],
                'by_method' => $byMethod,
            ],
            'receivables' => $this->ops->receivablesAsOf($date),
            'purchasing' => $this->ops->purchasing($date),
            'inventory' => $this->ops->inventoryAsOf($date),
        ];
    }
}
