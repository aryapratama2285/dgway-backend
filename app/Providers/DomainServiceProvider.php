<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Catalog\CatalogQueries;
use App\Application\Inventory\InventoryQueries;
use App\Application\Inventory\StockProjection;
use App\Application\Procurement\ProcurementQueries;
use App\Application\Sales\CustomerCreditGate;
use App\Application\Shared\Audit\AuditScope;
use App\Application\Sales\SalesQueries;
use App\Application\Sales\SaleLotConsumption;
use App\Infrastructure\Persistence\Query\DbSaleLotConsumption;
use App\Domain\Sales\CustomerReturnRepository;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Sales\SaleRepository;
use App\Infrastructure\Persistence\Query\DbSalesQueries;
use App\Infrastructure\Persistence\Repository\DbCustomerCreditGate;
use App\Infrastructure\Persistence\Repository\DbCustomerReturnRepository;
use App\Infrastructure\Persistence\Repository\DbPaymentRepository;
use App\Infrastructure\Persistence\Repository\DbSaleRepository;
use App\Domain\Inventory\DisallowNegativeStock;
use App\Domain\Inventory\InventoryOperationRepository;
use App\Domain\Inventory\LotRepository;
use App\Domain\Inventory\ReorderPointRepository;
use App\Infrastructure\Persistence\Repository\DbLotRepository;
use App\Infrastructure\Persistence\Repository\DbReorderPointRepository;
use App\Domain\Inventory\NegativeStockPolicy;
use App\Domain\Inventory\StockLedger;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Infrastructure\Persistence\DbStockProjection;
use App\Infrastructure\Persistence\Query\DbInventoryQueries;
use App\Infrastructure\Persistence\Query\DbProcurementQueries;
use App\Infrastructure\Persistence\Repository\DbGoodsReceiptRepository;
use App\Infrastructure\Persistence\Repository\DbInventoryOperationRepository;
use App\Infrastructure\Persistence\Repository\DbPurchaseOrderRepository;
use App\Infrastructure\Persistence\Repository\DbStockLedger;
use App\Application\Catalog\ProductUsageChecker;
use App\Application\Identity\AccessTokenService;
use App\Application\Identity\OperatorDirectory;
use App\Application\Identity\OperatorRegistry;
use App\Application\Identity\RegisterOperator;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Network\NetworkQueries;
use App\Application\Partner\PartnerQueries;
use App\Application\Shared\Idempotency\IdempotencyStore;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Network\LocationRepository;
use App\Domain\Network\NodeRepository;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Infrastructure\Identity\UuidV7IdentityGenerator;
use App\Infrastructure\Persistence\DatabaseProductUsageChecker;
use App\Infrastructure\Persistence\PostgresConcurrencyErrorDetector;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use App\Infrastructure\Persistence\Query\DbCatalogQueries;
use App\Infrastructure\Persistence\Query\DbLotQueries;
use App\Application\Inventory\LotQueries;
use App\Application\Operations\OperationsQueries;
use App\Infrastructure\Persistence\Query\DbOperationsQueries;
use App\Application\Shared\Security\Authorizer;
use App\Infrastructure\Security\RoleBasedAuthorizer;
use App\Infrastructure\Persistence\Query\DbNetworkQueries;
use App\Infrastructure\Persistence\Query\DbPartnerQueries;
use App\Infrastructure\Persistence\Repository\DbBusinessPartnerRepository;
use App\Infrastructure\Persistence\Repository\DbLocationRepository;
use App\Infrastructure\Persistence\Repository\DbNodeRepository;
use App\Infrastructure\Persistence\Repository\DbProductCategoryRepository;
use App\Infrastructure\Persistence\Repository\DbProductRepository;
use App\Infrastructure\Persistence\Repository\DbUnitOfMeasureRepository;
use App\Application\Shared\Security\ActorContext;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Time\Clock;
use App\Infrastructure\Authentication\EloquentOperatorDirectory;
use App\Infrastructure\Authentication\LaravelActorContext;
use App\Infrastructure\Authentication\SanctumAccessTokenService;
use App\Infrastructure\Persistence\DatabaseAuditRecorder;
use App\Infrastructure\Persistence\DatabaseIdempotencyStore;
use App\Infrastructure\Persistence\LaravelTransactionManager;
use App\Infrastructure\Persistence\PostgresDocumentNumberGenerator;
use App\Infrastructure\Time\SystemClock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Wires application ports (interfaces) to infrastructure adapters.
 * This is the only place that knows which concrete adapter backs a port.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConnectionInterface::class, fn (Application $app) => $app['db']->connection());

        $this->app->singleton(Clock::class, fn () => new SystemClock((string) config('dgway.business_timezone')));

        $this->app->bind(TransactionManager::class, LaravelTransactionManager::class);
        // Retry PostgreSQL deadlocks (40P01) AND serialization failures (40001).
        $this->app->singleton(ConcurrencyErrorDetector::class, PostgresConcurrencyErrorDetector::class);
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);
        $this->app->bind(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->bind(DocumentNumberGenerator::class, PostgresDocumentNumberGenerator::class);
        $this->app->bind(ActorContext::class, LaravelActorContext::class);
        $this->app->bind(Authorizer::class, RoleBasedAuthorizer::class);

        $this->app->bind(EloquentOperatorDirectory::class);
        $this->app->bind(OperatorDirectory::class, EloquentOperatorDirectory::class);
        $this->app->bind(OperatorRegistry::class, EloquentOperatorDirectory::class);
        $this->app->bind(AccessTokenService::class, fn (Application $app) => new SanctumAccessTokenService(
            $app->make(Clock::class),
            config('dgway.token_ttl_days') ?: null,
        ));

        // A2 master data
        $this->app->singleton(IdentityGenerator::class, UuidV7IdentityGenerator::class);
        $this->app->bind(ProductCategoryRepository::class, DbProductCategoryRepository::class);
        $this->app->bind(ProductRepository::class, DbProductRepository::class);
        $this->app->bind(UnitOfMeasureRepository::class, DbUnitOfMeasureRepository::class);
        $this->app->bind(BusinessPartnerRepository::class, DbBusinessPartnerRepository::class);
        $this->app->bind(LocationRepository::class, DbLocationRepository::class);
        $this->app->bind(NodeRepository::class, DbNodeRepository::class);
        $this->app->bind(CatalogQueries::class, DbCatalogQueries::class);
        $this->app->bind(PartnerQueries::class, DbPartnerQueries::class);
        $this->app->bind(NetworkQueries::class, DbNetworkQueries::class);
        $this->app->bind(ProductUsageChecker::class, fn (Application $app) => new DatabaseProductUsageChecker(
            $app->make(ConnectionInterface::class),
            (array) config('dgway.product_reference_tables', []),
        ));

        // A3 procurement + inventory
        $this->app->bind(PurchaseOrderRepository::class, DbPurchaseOrderRepository::class);
        $this->app->bind(GoodsReceiptRepository::class, DbGoodsReceiptRepository::class);
        $this->app->bind(StockLedger::class, DbStockLedger::class);
        $this->app->bind(InventoryOperationRepository::class, DbInventoryOperationRepository::class);
        $this->app->bind(LotRepository::class, DbLotRepository::class);
        $this->app->bind(ReorderPointRepository::class, DbReorderPointRepository::class);
        // Default policy: stock never goes below zero (A3 §17).
        $this->app->bind(NegativeStockPolicy::class, DisallowNegativeStock::class);
        $this->app->bind(InventoryQueries::class, DbInventoryQueries::class);
        $this->app->bind(LotQueries::class, DbLotQueries::class);
        // A5-C printing / scanning (read models + settings)
        $this->app->bind(\App\Application\Printing\PrintQueries::class, \App\Infrastructure\Printing\DbPrintQueries::class);
        $this->app->bind(\App\Application\Printing\PrintSettingsStore::class, \App\Infrastructure\Printing\DbPrintSettingsStore::class);
        $this->app->bind(\App\Application\Printing\PrintDefaults::class, fn () => new \App\Application\Printing\PrintDefaults(
            (string) config('dgway.print.business_name'), config('dgway.print.business_subtitle') ?: null, (int) config('dgway.print.receipt_width_mm')));
        $this->app->bind(OperationsQueries::class, DbOperationsQueries::class);
        $this->app->bind(StockProjection::class, DbStockProjection::class);
        $this->app->bind(ProcurementQueries::class, DbProcurementQueries::class);

        // request context for audit entries (one per process)
        $this->app->singleton(AuditScope::class);

        // A4 sales, payments, receivables
        $this->app->bind(SaleRepository::class, DbSaleRepository::class);
        $this->app->bind(PaymentRepository::class, DbPaymentRepository::class);
        $this->app->bind(CustomerReturnRepository::class, DbCustomerReturnRepository::class);
        $this->app->bind(CustomerCreditGate::class, DbCustomerCreditGate::class);
        $this->app->bind(SalesQueries::class, DbSalesQueries::class);
        $this->app->bind(SaleLotConsumption::class, DbSaleLotConsumption::class);

        $this->app->bind(RegisterOperator::class, fn (Application $app) => new RegisterOperator(
            $app->make(OperatorRegistry::class),
            $app->make(TransactionManager::class),
            $app->make(AuditRecorder::class),
            (int) config('dgway.password_min_length'),
        ));
    }
}
