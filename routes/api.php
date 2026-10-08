<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Controllers\Api\V1\Inventory\LotController;
use App\Http\Controllers\Api\V1\Inventory\ReorderPointController;
use App\Http\Controllers\Api\V1\MasterData\BusinessPartnerController;
use App\Http\Controllers\Api\V1\Operations\OperationsController;
use App\Http\Controllers\Api\V1\Printing\PrintController;
use App\Http\Controllers\Api\V1\MasterData\CategoryController;
use App\Http\Controllers\Api\V1\MasterData\LocationController;
use App\Http\Controllers\Api\V1\MasterData\NodeController;
use App\Http\Controllers\Api\V1\MasterData\ProductController;
use App\Http\Controllers\Api\V1\Procurement\GoodsReceiptController;
use App\Http\Controllers\Api\V1\Procurement\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Sales\CustomerReturnController;
use App\Http\Controllers\Api\V1\Sales\PaymentController;
use App\Http\Controllers\Api\V1\Sales\ReceivableController;
use App\Http\Controllers\Api\V1\Sales\ReportController;
use App\Http\Controllers\Api\V1\Sales\SaleController;
use App\Http\Middleware\EnsureOperatorIsActive;
use Illuminate\Support\Facades\Route;

/*
| DG Way Owner Operating App — API v1
|
| Conventions:
| - All endpoints are JSON over HTTPS under /api/v1.
| - Authenticated endpoints require "Authorization: Bearer <token>".
| - Every mutating business command (POST/PATCH below the 'idempotency'
|   group) requires an "Idempotency-Key" header; retries with the same key
|   return the original response and never create a duplicate.
| - Master data is never deleted: use /activate and /deactivate.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('api.v1.health');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('api.v1.health.ready');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::middleware(['auth:sanctum', EnsureOperatorIsActive::class, 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.v1.auth.me');

        // ── reads ────────────────────────────────────────────────────────
        // Operational readiness (A5-B): defaults, owner dashboard, daily activity, search.
        Route::get('config', [OperationsController::class, 'config']);
        Route::get('dashboard', [OperationsController::class, 'dashboard']);
        Route::get('activity', [OperationsController::class, 'activity']);
        Route::get('search', [OperationsController::class, 'search']);

        // Printing & scanning (A5-C): device-neutral print data + scan resolution, read-only.
        Route::get('print/settings', [PrintController::class, 'settings']);
        Route::get('sales/{id}/print', [PrintController::class, 'receipt'])->whereUuid('id');
        Route::get('purchase-orders/{id}/print', [PrintController::class, 'purchaseOrder'])->whereUuid('id');
        Route::get('goods-receipts/{id}/print', [PrintController::class, 'goodsReceipt'])->whereUuid('id');
        Route::get('lots/{lot}/label', [PrintController::class, 'lotLabel'])->where('lot', '[A-Za-z0-9-]+');
        Route::get('scan/resolve', [PrintController::class, 'resolve']);
        Route::get('uoms', [ProductController::class, 'uoms']);
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{id}', [CategoryController::class, 'show'])->whereUuid('id');
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{id}', [ProductController::class, 'show'])->whereUuid('id');
        Route::get('business-partners', [BusinessPartnerController::class, 'index']);
        Route::get('business-partners/{id}', [BusinessPartnerController::class, 'show'])->whereUuid('id');
        Route::get('locations', [LocationController::class, 'index']);
        Route::get('locations/{id}', [LocationController::class, 'show'])->whereUuid('id');
        Route::get('nodes', [NodeController::class, 'index']);
        Route::get('nodes/{id}', [NodeController::class, 'show'])->whereUuid('id');

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->whereUuid('id');
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index']);
        Route::get('goods-receipts/{id}', [GoodsReceiptController::class, 'show'])->whereUuid('id');
        Route::get('inventory/current', [InventoryController::class, 'current']);
        Route::get('inventory/balances', [InventoryController::class, 'balances']);
        Route::get('inventory/movements', [InventoryController::class, 'movements']);
        Route::get('inventory/integrity', [InventoryController::class, 'integrity']);
        Route::get('inventory/lot-balances', [LotController::class, 'balances']);

        // F3: inventory list, operation history, low-stock thresholds (reads).
        Route::get('inventory/stock', [InventoryController::class, 'stock']);
        Route::get('inventory/operations', [InventoryController::class, 'operations']);
        Route::get('inventory/operations/{id}', [InventoryController::class, 'operation'])->whereUuid('id');
        Route::get('inventory/reorder-points', [ReorderPointController::class, 'index']);

        // Lots (A5-B): {lot} = UUID or lot number.
        Route::get('lots', [LotController::class, 'index']);
        Route::get('lots/{lot}', [LotController::class, 'show'])->where('lot', '[A-Za-z0-9-]+');
        Route::get('lots/{lot}/movements', [LotController::class, 'movements'])->where('lot', '[A-Za-z0-9-]+');
        Route::get('lots/{lot}/lineage', [LotController::class, 'lineage'])->where('lot', '[A-Za-z0-9-]+');
        Route::get('lots/{lot}/destinations', [LotController::class, 'destinations'])->where('lot', '[A-Za-z0-9-]+');

        Route::get('sales', [SaleController::class, 'index']);
        Route::get('sales/{id}', [SaleController::class, 'show'])->whereUuid('id');
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{id}', [PaymentController::class, 'show'])->whereUuid('id');
        Route::get('customer-returns', [CustomerReturnController::class, 'index']);
        Route::get('customer-returns/{id}', [CustomerReturnController::class, 'show'])->whereUuid('id');
        Route::get('receivables', [ReceivableController::class, 'bySale']);
        Route::get('receivables/customers', [ReceivableController::class, 'byCustomer']);
        Route::get('receivables/summary', [ReceivableController::class, 'summary']);
        Route::get('reports/daily', [ReportController::class, 'daily']);
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/sales-by-product', [ReportController::class, 'salesByProduct']);

        // ── idempotent commands ──────────────────────────────────────────
        Route::middleware('idempotency')->group(function (): void {
            Route::post('categories', [CategoryController::class, 'store']);
            Route::patch('categories/{id}', [CategoryController::class, 'update'])->whereUuid('id');
            Route::post('categories/{id}/activate', [CategoryController::class, 'activate'])->whereUuid('id');
            Route::post('categories/{id}/deactivate', [CategoryController::class, 'deactivate'])->whereUuid('id');

            Route::post('products', [ProductController::class, 'store']);
            Route::patch('products/{id}', [ProductController::class, 'update'])->whereUuid('id');
            Route::post('products/{id}/activate', [ProductController::class, 'activate'])->whereUuid('id');
            Route::post('products/{id}/deactivate', [ProductController::class, 'deactivate'])->whereUuid('id');

            Route::post('business-partners', [BusinessPartnerController::class, 'store']);
            Route::patch('business-partners/{id}', [BusinessPartnerController::class, 'update'])->whereUuid('id');
            Route::post('business-partners/{id}/activate', [BusinessPartnerController::class, 'activate'])->whereUuid('id');
            Route::post('business-partners/{id}/deactivate', [BusinessPartnerController::class, 'deactivate'])->whereUuid('id');
            Route::post('business-partners/{id}/roles', [BusinessPartnerController::class, 'assignRole'])->whereUuid('id');
            Route::patch('business-partners/{id}/roles/{role}', [BusinessPartnerController::class, 'updateRole'])->whereUuid('id')->whereIn('role', ['supplier', 'customer']);
            Route::post('business-partners/{id}/roles/{role}/activate', [BusinessPartnerController::class, 'activateRole'])->whereUuid('id')->whereIn('role', ['supplier', 'customer']);
            Route::post('business-partners/{id}/roles/{role}/deactivate', [BusinessPartnerController::class, 'deactivateRole'])->whereUuid('id')->whereIn('role', ['supplier', 'customer']);

            Route::post('locations', [LocationController::class, 'store']);
            Route::patch('locations/{id}', [LocationController::class, 'update'])->whereUuid('id');
            Route::post('locations/{id}/activate', [LocationController::class, 'activate'])->whereUuid('id');
            Route::post('locations/{id}/deactivate', [LocationController::class, 'deactivate'])->whereUuid('id');

            Route::patch('print/settings', [PrintController::class, 'updateSettings']);

            Route::post('nodes', [NodeController::class, 'store']);
            Route::patch('nodes/{id}', [NodeController::class, 'update'])->whereUuid('id');
            Route::post('nodes/{id}/activate', [NodeController::class, 'activate'])->whereUuid('id');
            Route::post('nodes/{id}/deactivate', [NodeController::class, 'deactivate'])->whereUuid('id');

            // Procurement & receiving (A3). Posted documents are never edited
            // or deleted; corrections go through /reverse.
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::post('purchase-orders/{id}/confirm', [PurchaseOrderController::class, 'confirm'])->whereUuid('id');
            Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->whereUuid('id');

            Route::post('goods-receipts', [GoodsReceiptController::class, 'store']);
            Route::post('goods-receipts/{id}/post', [GoodsReceiptController::class, 'post'])->whereUuid('id');
            Route::post('goods-receipts/{id}/reverse', [GoodsReceiptController::class, 'reverse'])->whereUuid('id');
            Route::post('goods-receipts/{id}/cancel', [GoodsReceiptController::class, 'cancel'])->whereUuid('id');

            // Inventory operations: every stock change is a reasoned document
            // that appends ledger movements. There is no "set stock" endpoint.
            Route::post('inventory/losses', [InventoryController::class, 'loss']);
            Route::post('inventory/regrades', [InventoryController::class, 'regrade']);
            Route::post('inventory/recoveries', [InventoryController::class, 'recovery']);
            Route::post('inventory/supplier-returns', [InventoryController::class, 'supplierReturn']);
            Route::post('inventory/adjustments', [InventoryController::class, 'adjustment']);
            // F3: internal consumption (own use of packaging/consumables) and low-stock thresholds.
            Route::post('inventory/consumptions', [InventoryController::class, 'consumption']);
            Route::post('inventory/reorder-points', [ReorderPointController::class, 'store']);

            // Sales, payments, returns (A4). A confirmed sale is never edited:
            // corrections are returns, payment reversals or a sale reversal.
            Route::post('sales', [SaleController::class, 'store']);
            Route::post('sales/{id}/confirm', [SaleController::class, 'confirm'])->whereUuid('id');
            Route::post('sales/{id}/confirm-with-credit-override', [SaleController::class, 'confirmWithCreditOverride'])->whereUuid('id');
            Route::post('sales/{id}/cancel', [SaleController::class, 'cancel'])->whereUuid('id');
            Route::post('sales/{id}/reverse', [SaleController::class, 'reverse'])->whereUuid('id');
            Route::post('sales/{id}/payments', [SaleController::class, 'pay'])->whereUuid('id');
            Route::post('sales/{id}/returns', [SaleController::class, 'recordReturn'])->whereUuid('id');
            Route::post('payments/{id}/reverse', [PaymentController::class, 'reverse'])->whereUuid('id');
        });
    });
});
