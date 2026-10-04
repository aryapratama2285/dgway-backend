<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Identity\Id;

interface PaymentRepository
{
    public function get(Id $id): Payment;

    /** @return list<Payment> payments of the sale that are not reversed */
    public function validPaymentsOfSale(Id $saleId): array;

    /** @return list<Payment> all payments of the sale, oldest first */
    public function paymentsOfSale(Id $saleId): array;

    public function add(Payment $payment): void;

    /** Appends the payment's reversal record (UNIQUE per payment in the database). */
    public function addReversal(Payment $payment): void;
}
