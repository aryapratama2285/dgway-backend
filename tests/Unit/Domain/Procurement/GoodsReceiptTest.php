<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Procurement;

use App\Domain\Procurement\Disposition;
use App\Domain\Procurement\DispositionType as D;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptLine;
use App\Domain\Procurement\GoodsReceiptStatus;
use App\Domain\Procurement\InvalidTransition;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderLine;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Math\Decimal;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GoodsReceiptTest extends TestCase
{
    private Id $supplier;
    private Id $node;
    private Id $egg;
    private Id $afkir;

    protected function setUp(): void
    {
        [$this->supplier, $this->node, $this->egg, $this->afkir] = [PurchaseOrderTest::id(), PurchaseOrderTest::id(), PurchaseOrderTest::id(), PurchaseOrderTest::id()];
    }

    private function kg(string $v): Quantity
    {
        return Quantity::of($v, Uom::kg());
    }

    /** @param list<Disposition> $dispositions */
    private function line(string $received, array $dispositions, string $cost = '23000', ?Id $poLine = null): GoodsReceiptLine
    {
        return GoodsReceiptLine::create(PurchaseOrderTest::id(), 1, $this->egg, $this->kg($received), Money::of($cost), $dispositions, $poLine);
    }

    private function receipt(GoodsReceiptLine $line, ?PurchaseOrder $po = null): GoodsReceipt
    {
        return GoodsReceipt::draft(PurchaseOrderTest::id(), DocumentNumber::parse('GR-261003-001'), $this->supplier, $this->node,
            BusinessDate::fromString('2026-10-03'), new DateTimeImmutable('2026-10-03T01:00:00Z'), [$line], $po);
    }

    public function test_owner_scenario_150_received_140_accept_10_loss(): void
    {
        $gr = $this->receipt($this->line('150', [Disposition::of(D::ACCEPT, $this->kg('140')), Disposition::of(D::LOSS, $this->kg('10'), reason: 'retak')]));

        $this->assertSame('3450000.0000', $gr->totalCost()->toStorage(), 'cost is for everything received');
        $y = $gr->yieldSummary()[0];
        $this->assertSame(['150.0000', '140.0000', '10.0000'], [$y['received'], $y['accepted'], $y['loss']]);
        $this->assertSame('24642.8571', $y['cost_per_accepted_unit'], 'analytical effective cost 3.450.000 / 140');
    }

    public function test_mixed_dispositions_conserve_quantity(): void
    {
        $gr = $this->receipt($this->line('150', [
            Disposition::of(D::ACCEPT, $this->kg('140')),
            Disposition::of(D::RETURN_TO_SUPPLIER, $this->kg('5')),
            Disposition::of(D::REGRADE, $this->kg('5'), $this->afkir),
        ]));

        $y = $gr->yieldSummary()[0];
        $this->assertSame(['5.0000', '5.0000', '0.0000'], [$y['returned_to_supplier'], $y['regraded'], $y['loss']]);
    }

    public function test_missing_disposition_is_rejected(): void
    {
        try {
            $this->line('150', [Disposition::of(D::ACCEPT, $this->kg('140'))]);
            $this->fail('150 received with only 140 accounted for was accepted');
        } catch (InvariantViolation $e) {
            $this->assertSame('MASS_BALANCE_VIOLATED', $e->errorCode());
            $this->assertSame('140.0000', $e->context()['dispositions_total']);
        }
    }

    public function test_over_disposition_is_rejected(): void
    {
        $this->expectExceptionMessage('dispositions total 151.0000');
        $this->line('150', [Disposition::of(D::ACCEPT, $this->kg('141')), Disposition::of(D::LOSS, $this->kg('10'))]);
    }

    public function test_no_disposition_at_all_is_rejected(): void
    {
        $this->expectExceptionMessage('needs a disposition');
        $this->line('150', []);
    }

    public function test_disposition_rules(): void
    {
        foreach ([
            'regrade without target' => fn () => Disposition::of(D::REGRADE, $this->kg('5')),
            'loss with target' => fn () => Disposition::of(D::LOSS, $this->kg('5'), $this->afkir),
            'zero quantity' => fn () => Disposition::of(D::ACCEPT, $this->kg('0')),
            'recovery at receipt' => fn () => Disposition::of(D::RECOVERY, $this->kg('1')),
            'regrade to same product' => fn () => $this->line('5', [Disposition::of(D::REGRADE, $this->kg('5'), $this->egg)]),
            'unit mismatch' => fn () => $this->line('5', [Disposition::of(D::ACCEPT, Quantity::of('5', Uom::pcs()))]),
        ] as $label => $bad) {
            try {
                $bad();
                $this->fail("accepted: {$label}");
            } catch (InvariantViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Property test: for many random receipts, any split of the received
     * quantity across dispositions is accepted, and any split that differs by
     * even one gram is rejected.
     */
    public function test_mass_balance_property_over_many_random_combinations(): void
    {
        mt_srand(20261003);
        $types = [D::ACCEPT, D::LOSS, D::RETURN_TO_SUPPLIER, D::REGRADE];

        for ($i = 0; $i < 400; $i++) {
            $receivedGrams = mt_rand(1, 999_999);           // up to 999.999 kg
            $parts = mt_rand(1, 4);
            $remaining = $receivedGrams;
            $splits = [];
            for ($p = 0; $p < $parts - 1 && $remaining > 1; $p++) {
                $take = mt_rand(1, $remaining - 1);
                $splits[] = $take;
                $remaining -= $take;
            }
            $splits[] = $remaining;

            $dispositions = array_map(function (int $g, int $idx) use ($types) {
                $type = $types[$idx % 4];

                return Disposition::of($type, $this->grams($g), $type === D::REGRADE ? $this->afkir : null);
            }, $splits, array_keys($splits));

            $line = $this->line($this->gramsToKg($receivedGrams), $dispositions);
            $sum = array_sum(array_map(fn (D $t) => (int) round((float) $line->quantityOf($t)->toStorage() * 1000), $types));
            $this->assertSame($receivedGrams, $sum, "iteration {$i}: dispositions must sum to received");

            // Perturb by ±1 gram: must be rejected.
            $delta = mt_rand(0, 1) === 1 ? 1 : -1;
            if ($receivedGrams + $delta < 1) {
                $delta = 1;
            }
            try {
                $this->line($this->gramsToKg($receivedGrams + $delta), $dispositions);
                $this->fail("iteration {$i}: 1 g imbalance accepted");
            } catch (InvariantViolation $e) {
                $this->assertSame('MASS_BALANCE_VIOLATED', $e->errorCode());
            }
        }
    }

    public function test_post_once_then_reverse_once(): void
    {
        $gr = $this->receipt($this->line('10', [Disposition::of(D::ACCEPT, $this->kg('10'))]));
        $gr->post(new DateTimeImmutable());
        $this->assertSame(GoodsReceiptStatus::POSTED, $gr->status());

        try {
            $gr->post(new DateTimeImmutable());
            $this->fail('double post accepted');
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }

        $gr->reverse(new DateTimeImmutable(), 'salah supplier');
        $this->assertSame(GoodsReceiptStatus::REVERSED, $gr->status());
        $this->expectException(InvalidTransition::class);
        $gr->reverse(new DateTimeImmutable(), 'lagi');
    }

    public function test_draft_cannot_be_reversed_and_posted_cannot_be_cancelled(): void
    {
        $gr = $this->receipt($this->line('10', [Disposition::of(D::ACCEPT, $this->kg('10'))]));
        try {
            $gr->reverse(new DateTimeImmutable(), 'x');
            $this->fail();
        } catch (InvalidTransition) {
            $this->addToAssertionCount(1);
        }
        $gr->post(new DateTimeImmutable());
        $this->expectException(InvalidTransition::class);
        $gr->cancel('x');
    }

    public function test_receipt_against_po_validates_match_and_open_quantity(): void
    {
        $poLine = PurchaseOrderLine::create(PurchaseOrderTest::id(), 1, $this->egg, $this->kg('500'), Money::of('23000'));
        $po = PurchaseOrder::draft(PurchaseOrderTest::id(), DocumentNumber::parse('PO-261003-001'), $this->supplier, $this->node, BusinessDate::fromString('2026-10-03'), [$poLine]);

        try {
            $this->receipt($this->line('200', [Disposition::of(D::ACCEPT, $this->kg('200'))], poLine: $poLine->id), $po);
            $this->fail('draft PO received');
        } catch (InvariantViolation $e) {
            $this->assertSame('PO_NOT_RECEIVABLE', $e->errorCode());
        }

        $po->confirm();
        $gr = $this->receipt($this->line('200', [Disposition::of(D::ACCEPT, $this->kg('200'))], poLine: $poLine->id), $po);
        $this->assertTrue($gr->purchaseOrderId?->equals($po->id()));

        $this->expectExceptionMessage('Over-receipt is not allowed');
        $this->receipt($this->line('500.5', [Disposition::of(D::ACCEPT, $this->kg('500.5'))], poLine: $poLine->id), $po);
    }

    public function test_direct_receipt_cannot_reference_po_lines(): void
    {
        $this->expectExceptionMessage('direct receipt');
        $this->receipt($this->line('1', [Disposition::of(D::ACCEPT, $this->kg('1'))], poLine: PurchaseOrderTest::id()));
    }

    private function grams(int $g): Quantity
    {
        return Quantity::of($this->gramsToKg($g), Uom::kg());
    }

    private function gramsToKg(int $g): string
    {
        return (string) Decimal::of((string) $g)->dividedBy(Decimal::of('1000'), 3);
    }
}
