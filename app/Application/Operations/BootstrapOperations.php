<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Identity\RegisterOperator;
use App\Application\Network\BootstrapPrimaryNode;
use App\Application\Partner\CreateBusinessPartner;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use InvalidArgumentException;

/**
 * A5-B §20 — prepares a fresh installation for daily operation. Every step
 * checks before it creates, so running it again changes nothing.
 *
 *   1. base units of measure (seeded by migrations; verified here)
 *   2. default site + node (DG-WAY-PRIMARY)
 *   3. walk-in customer (WALK-IN, cash only: term 0, no credit)
 *   4. owner operator — ONLY when an email AND a password are supplied by the
 *      person running it; there is no default account and no default password
 *   5. operational defaults (config) reported back
 */
final class BootstrapOperations
{
    public const REQUIRED_UOMS = ['KG'];

    public function __construct(
        private readonly OperationsQueries $queries,
        private readonly BootstrapPrimaryNode $node,
        private readonly CreateBusinessPartner $partners,
        private readonly RegisterOperator $operators,
    ) {
    }

    /** @return list<array{step: string, status: string, detail: string}> */
    public function execute(Actor $actor, string $nodeCode, string $walkInCode, ?string $ownerEmail = null, ?string $ownerName = null, ?string $ownerPassword = null): array
    {
        $report = [];

        $uoms = $this->queries->uomCodes();
        $missing = array_values(array_diff(self::REQUIRED_UOMS, $uoms));
        if ($missing !== []) {
            throw InvariantViolation::because('BOOTSTRAP_UOM_MISSING', 'Base units missing: '.implode(', ', $missing).'. Run the migrations first.');
        }
        $report[] = ['step' => 'uoms', 'status' => 'ok', 'detail' => implode(', ', $uoms)];

        $node = $this->node->execute($actor, $nodeCode);
        $report[] = ['step' => 'default_node', 'status' => $node['created'] ? 'created' : 'exists', 'detail' => "{$nodeCode} {$node['node']->id()->value}"];

        $walkIn = $this->queries->partnerByCode($walkInCode, false);
        if ($walkIn === null) {
            $p = $this->partners->execute($actor, [
                'code' => $walkInCode, 'name' => 'Pelanggan Umum (Walk-in)', 'party_type' => 'INDIVIDUAL',
                'customer' => ['customer_type' => 'WALK_IN', 'term_days' => 0, 'credit_limit' => null],
                'notes' => 'Created by dgway:bootstrap. Cash only (no credit).',
            ]);
            $report[] = ['step' => 'walk_in_customer', 'status' => 'created', 'detail' => "{$walkInCode} {$p->id()->value}"];
        } else {
            $report[] = ['step' => 'walk_in_customer', 'status' => 'exists', 'detail' => "{$walkInCode} {$walkIn['id']}"];
        }

        if ($ownerEmail === null) {
            $report[] = ['step' => 'owner', 'status' => $this->queries->operatorCount() === 0 ? 'missing' : 'skipped',
                'detail' => $this->queries->operatorCount() === 0
                    ? 'No operator exists. Re-run with --owner-email=… --password-stdin (no default account is ever created).'
                    : 'Operators already exist; no owner requested.'];
        } else {
            if ($ownerPassword === null || $ownerPassword === '') {
                throw InvariantViolation::because('BOOTSTRAP_PASSWORD_REQUIRED', 'An owner needs a password supplied by you (STDIN or prompt). There is no default password.');
            }
            try {
                $account = $this->operators->execute($actor, $ownerName ?: 'Owner', $ownerEmail, $ownerPassword);
                $report[] = ['step' => 'owner', 'status' => 'created', 'detail' => "{$account->email} {$account->id}"];
            } catch (InvalidArgumentException $e) {
                if (! str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
                $report[] = ['step' => 'owner', 'status' => 'exists', 'detail' => $ownerEmail.' (password NOT changed)'];
            }
        }

        return $report;
    }
}
