<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Identity\Id;

interface CustomerReturnRepository
{
    public function get(Id $id): CustomerReturn;

    /** @return list<CustomerReturn> */
    public function returnsOfSale(Id $saleId): array;

    public function add(CustomerReturn $return): void;
}
