<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Domain\Sales\CreditLimitOverride;
use App\Domain\Sales\Sale;
use App\Domain\Shared\Identity\Actor;

/**
 * A5-B §18 — confirm a DRAFT credit sale that exceeds the customer's credit
 * limit, as an explicit OWNER decision with a mandatory reason.
 *
 * - permission OVERRIDE_CREDIT_LIMIT via the Authorizer (checked early for a
 *   fast 403 and again inside ConfirmSale's transaction);
 * - the customer's credit limit is NOT changed;
 * - the same customer credit lock as a normal confirmation, so concurrent
 *   overrides see each other's exposure;
 * - audit sale.credit_limit_overridden (customer, sale, actor, reason,
 *   limit, exposure before/after, timestamp; idempotency key + command come
 *   from the AuditScope of the idempotent request).
 */
final class ConfirmSaleWithCreditOverride
{
    public function __construct(private readonly ConfirmSale $confirm, private readonly Authorizer $authorizer)
    {
    }

    /** @param list<array<string, mixed>> $payments */
    public function execute(Actor $actor, string $saleId, string $reason, array $payments = [], ?int $expectedVersion = null): Sale
    {
        $this->authorizer->authorize($actor, Permission::OVERRIDE_CREDIT_LIMIT);
        $override = CreditLimitOverride::approved($reason, (string) $actor->userId);

        return $this->confirm->execute($actor, $saleId, $payments, $expectedVersion, $override);
    }
}
