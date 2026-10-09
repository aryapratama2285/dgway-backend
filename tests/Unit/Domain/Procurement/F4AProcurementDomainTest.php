<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Procurement;

use App\Domain\Procurement\InvalidTransition;
use App\Domain\Procurement\PurchasedIn;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderCloseReason;
use App\Domain\Procurement\PurchaseOrderLine;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\UnitConversion;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class F4AProcurementDomainTest extends TestCase
{
    private function po(string $qty = '100'): PurchaseOrder
    {
        $line = PurchaseOrderLine::create(PurchaseOrderTest::id(), 1, PurchaseOrderTest::id(), Quantity::of($qty, Uom::kg()), Money::of('23500'));

        return PurchaseOrder::draft(PurchaseOrderTest::id(), DocumentNumber::parse('PO-261003-001'), PurchaseOrderTest::id(), PurchaseOrderTest::id(), BusinessDate::fromString('2026-10-03'), [$line]);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (InvariantViolation|InvalidTransition $e) {
            return $e->errorCode();
        }
        $this->fail('expected a domain error');
    }

    public function test_unit_conversion_is_exact_for_quantities_and_half_up_for_cost(): void
    {
        $ikat = Uom::ikat();
        $c = UnitConversion::of($ikat, Uom::pcs(), '30');
        $this->assertSame('120.0000', $c->toBase(Quantity::of('4', $ikat))->toStorage());
        $this->assertSame('1666.6667', $c->baseUnitCost(Money::of('50000'))->toStorage());
        $this->assertSame('UOM_CONVERSION_INVALID', $this->code(fn () => UnitConversion::of($ikat, Uom::pcs(), '2.5')->toBase(Quantity::of('1', $ikat))));
        $this->assertSame('UOM_CONVERSION_INVALID', $this->code(fn () => UnitConversion::of($ikat, Uom::pcs(), '0')));
        $this->assertSame('UOM_CONVERSION_INVALID', $this->code(fn () => UnitConversion::of(Uom::pcs(), Uom::pcs(), '2')));
        $this->assertTrue(UnitConversion::of(Uom::kg(), Uom::kg(), '1')->isIdentity());

        $p = new PurchasedIn($c, Quantity::of('4', $ikat), Money::of('50000'));
        $this->assertSame(['120.0000', '1666.6667', '200000.0000'], [$p->baseQuantity()->toStorage(), $p->baseUnitCost()->toStorage(), $p->total()->toStorage()]);
        $line = PurchaseOrderLine::createPurchased(PurchaseOrderTest::id(), 1, PurchaseOrderTest::id(), $p);
        $this->assertSame(['120.0000', '1666.6667', '200000.0000'], [$line->orderedQuantity->toStorage(), $line->unitCost->toStorage(), $line->lineTotal()->toStorage()]);
    }

    public function test_close_keeps_ordered_and_received_and_is_terminal(): void
    {
        $po = $this->po();
        $po->confirm(new DateTimeImmutable('2026-10-03T02:00:00Z'), 'u1');
        $at = new DateTimeImmutable('2026-10-04T02:00:00Z');
        $this->assertSame('INVALID_STATUS_TRANSITION', $this->code(fn () => $po->close(PurchaseOrderCloseReason::SUPPLIER_SHORTFALL, null, $at)));

        $line = $po->lines()[0];
        $po->recordReceipt($line->id, Quantity::of('90', Uom::kg()));
        $this->assertSame('CLOSE_REASON_REQUIRED', $this->code(fn () => $po->close(PurchaseOrderCloseReason::OTHER, '  ', $at)));
        $po->close(PurchaseOrderCloseReason::SUPPLIER_SHORTFALL, null, $at, 'u1');

        $this->assertSame(PurchaseOrderStatus::CLOSED, $po->status());
        $l = $po->lines()[0];
        $this->assertSame(['100.0000', '90.0000', '10.0000'], [$l->orderedQuantity->toStorage(), $l->receivedQuantity()->toStorage(), $l->unfulfilledQuantity()->toStorage()]);
        $this->assertSame([$at, PurchaseOrderCloseReason::SUPPLIER_SHORTFALL], [$po->lifecycle()->closedAt, $po->lifecycle()->closeReason]);
        foreach ([fn () => $po->recordReceipt($l->id, Quantity::of('1', Uom::kg())), fn () => $po->reverseReceipt($l->id, Quantity::of('1', Uom::kg())),
            fn () => $po->close(PurchaseOrderCloseReason::PURCHASE_STOPPED, null, $at)] as $op) {
            $this->assertSame('INVALID_STATUS_TRANSITION', $this->code($op));
        }
        $this->assertSame('PO_HAS_RECEIPTS', $this->code(fn () => $po->cancel('x')), 'a closed order has receipts: never cancellable');
    }

    public function test_only_a_draft_can_be_revised(): void
    {
        $po = $this->po();
        $lines = [PurchaseOrderLine::create(PurchaseOrderTest::id(), 1, PurchaseOrderTest::id(), Quantity::of('5', Uom::kg()), Money::of('1'))];
        $revised = PurchaseOrder::revisedDraft($po, $po->supplierId, $po->nodeId, $po->businessDate, $lines, 'n');
        $this->assertSame([$po->id()->value, $po->number->toString(), '5.0000'], [$revised->id()->value, $revised->number->toString(), $revised->lines()[0]->orderedQuantity->toStorage()]);
        $this->assertSame('PO_LINES_REQUIRED', $this->code(fn () => PurchaseOrder::revisedDraft($po, $po->supplierId, $po->nodeId, $po->businessDate, [], null)));
        $po->confirm();
        $this->assertSame('INVALID_STATUS_TRANSITION', $this->code(fn () => PurchaseOrder::revisedDraft($po, $po->supplierId, $po->nodeId, $po->businessDate, $lines, null)));
    }
}
