<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Time\BusinessDate;
use LogicException;
use RuntimeException;
use Tests\DatabaseTestCase;

final class DocumentNumberGeneratorTest extends DatabaseTestCase
{
    private DocumentNumberGenerator $numbers;
    private TransactionManager $tx;
    private BusinessDate $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->numbers = $this->app->make(DocumentNumberGenerator::class);
        $this->tx = $this->app->make(TransactionManager::class);
        $this->day = BusinessDate::fromString('2026-10-03');
    }

    public function test_sequential_numbers_per_prefix_and_day(): void
    {
        $issued = $this->tx->run(fn () => [
            (string) $this->numbers->next('EGG', $this->day),
            (string) $this->numbers->next('EGG', $this->day),
            (string) $this->numbers->next('SO', $this->day),
            (string) $this->numbers->next('EGG', $this->day->addDays(1)),
            (string) $this->numbers->next('EGG-BDG', $this->day),
        ]);

        $this->assertSame(['EGG-261003-001', 'EGG-261003-002', 'SO-261003-001', 'EGG-261004-001', 'EGG-BDG-261003-001'], $issued);
    }

    public function test_rolled_back_transaction_releases_the_number(): void
    {
        $this->tx->run(fn () => $this->numbers->next('SL', $this->day));

        try {
            $this->tx->run(function (): void {
                $this->numbers->next('SL', $this->day); // would be 002
                throw new RuntimeException('sale failed');
            });
        } catch (RuntimeException) {
        }

        $next = $this->tx->run(fn () => (string) $this->numbers->next('SL', $this->day));
        $this->assertSame('SL-261003-002', $next, 'no gap after a rolled-back transaction');
    }

    public function test_must_be_called_inside_a_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->numbers->next('SO', $this->day);
    }
}
