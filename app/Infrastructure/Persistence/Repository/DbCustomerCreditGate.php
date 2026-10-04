<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Application\Sales\CustomerCreditGate;
use App\Domain\Shared\Exception\EntityNotFound;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Lock target: the customer's row in business_partner_roles (role CUSTOMER).
 * SELECT … FOR UPDATE serialises all credit confirmations of one customer;
 * it also blocks a concurrent change of that customer's terms until the sale
 * commits. Exposure is read AFTER the lock, from the generated
 * sales.outstanding column (index sales_open_customer_idx).
 */
final class DbCustomerCreditGate implements CustomerCreditGate
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function lock(Id $customerId): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Credit lock must be taken inside a transaction.');
        }
        $row = $this->db->selectOne("SELECT id FROM business_partner_roles WHERE partner_id = ? AND role = 'CUSTOMER' FOR UPDATE", [$customerId->value]);
        if ($row === null) {
            throw EntityNotFound::of('Customer role', $customerId->value);
        }
    }

    public function outstandingOf(Id $customerId): Money
    {
        $sum = $this->db->selectOne('SELECT COALESCE(sum(outstanding), 0)::numeric(19,4) AS s FROM sales WHERE customer_id = ? AND outstanding > 0', [$customerId->value])->s;

        return Money::of((string) $sum);
    }
}
