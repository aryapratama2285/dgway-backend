<?php

/**
 * Separate OS process used by multi-process integration tests to play
 * "the other phone". It boots the real application against the same test
 * database and runs one scenario, reporting progress on STDOUT:
 *
 *   ACQUIRED   — it holds the contested lock/key inside an open transaction
 *   DONE …     — it committed
 *   ERROR …    — it failed (with the error code)
 *
 * Usage: php tests/Support/worker.php <scenario> [args…]
 */

declare(strict_types=1);

use App\Application\Catalog\CreateProduct;
use App\Application\Inventory\RecordInventoryOperation;
use App\Application\Inventory\StockProjection;
use App\Application\Procurement\PostGoodsReceipt;
use App\Application\Sales\ConfirmSale;
use App\Application\Sales\ConfirmSaleWithCreditOverride;
use App\Application\Sales\CreateSale;
use App\Application\Sales\RecordPayment;
use App\Application\Sales\ReverseSale;
use App\Application\Sales\ReversePayment;
use App\Application\Procurement\ReverseGoodsReceipt;
use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Idempotency\CommandResult;
use App\Application\Shared\Idempotency\IdempotencyKey;
use App\Application\Shared\Idempotency\IdempotentCommandExecutor;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Identity\Actor;
use App\Http\Resources\Api\V1\ProcurementResources;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$say = static function (string $line): void {
    fwrite(STDOUT, $line."\n");
    fflush(STDOUT);
};

[$script, $scenario] = [$argv[0], $argv[1] ?? ''];

try {
    switch ($scenario) {
        // Holds an idempotency key mid-transaction, then commits.
        case 'hold-idempotent': {
            [, , $userId, $key, $holdMs] = $argv;
            $actor = Actor::operator($userId, 'worker');
            $outcome = $app->make(IdempotentCommandExecutor::class)->execute(
                $actor, IdempotencyKey::fromString($key), 'test.concurrent', ['qty' => '35'],
                function () use ($app, $actor, $say, $holdMs): CommandResult {
                    $app->make(AuditRecorder::class)->record(new AuditEntry($actor, 'test.side_effect', 'test', after: ['by' => 'worker']));
                    $say('ACQUIRED');
                    usleep((int) $holdMs * 1000);

                    return new CommandResult(201, ['marker' => 'worker']);
                },
            );
            $say('DONE replayed='.($outcome->replayed ? 'true' : 'false'));
            break;
        }

        // Inserts a product (holding the SKU's unique-index slot), then commits.
        case 'hold-create-product': {
            [, , $userId, $categoryId, $sku, $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $categoryId, $sku, $say, $holdMs): void {
                $app->make(CreateProduct::class)->execute(Actor::operator($userId, 'worker'), $sku, 'Worker product', $categoryId, 'KG');
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Deadlock partner: lock row B, wait for "go" on STDIN, sleep, lock row A, commit.
        case 'deadlock-partner': {
            [, , $rowA, $rowB, $delayMs] = $argv;
            DB::transaction(function () use ($rowA, $rowB, $say, $delayMs): void {
                DB::selectOne('SELECT 1 FROM document_sequences WHERE prefix = ? FOR UPDATE', [$rowB]);
                $say('ACQUIRED');
                fgets(STDIN); // parent says "go" once it holds row A
                usleep((int) $delayMs * 1000);
                DB::selectOne('SELECT 1 FROM document_sequences WHERE prefix = ? FOR UPDATE', [$rowA]);
            });
            $say('DONE');
            break;
        }

        // Posts a goods receipt and keeps the transaction open (GR + PO +
        // balance rows locked). With a key, goes through the idempotent
        // executor exactly like POST /goods-receipts/{id}/post does.
        case 'hold-post-receipt': {
            [, , $userId, $receiptId, $holdMs] = $argv;
            $key = $argv[5] ?? null;
            $actor = Actor::operator($userId, 'worker');
            $work = function () use ($app, $actor, $receiptId, $say, $holdMs) {
                $gr = $app->make(PostGoodsReceipt::class)->execute($actor, $receiptId);
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);

                return $gr;
            };
            if ($key === null) {
                $app->make(TransactionManager::class)->run($work);
                $say('DONE');
                break;
            }
            $outcome = $app->make(IdempotentCommandExecutor::class)->execute(
                $actor, IdempotencyKey::fromString($key), 'goods_receipt.post', ['route' => ['id' => $receiptId], 'body' => []],
                function () use ($work): CommandResult {
                    $gr = $work();

                    return new CommandResult(200, ['data' => ProcurementResources::goodsReceipt($gr)], 'goods_receipt', $gr->id()->value);
                },
            );
            $say('DONE replayed='.($outcome->replayed ? 'true' : 'false'));
            break;
        }

        // Reverses a receipt and holds the transaction open.
        case 'hold-reverse-receipt': {
            [, , $userId, $receiptId, $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $receiptId, $say, $holdMs): void {
                $app->make(ReverseGoodsReceipt::class)->execute(Actor::operator($userId, 'worker'), $receiptId, 'worker reversal');
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Records a loss and holds the transaction (balance row locked).
        case 'hold-loss': {
            [, , $userId, $nodeId, $productId, $qty, $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $nodeId, $productId, $qty, $say, $holdMs): void {
                $app->make(RecordInventoryOperation::class)->recordLoss(Actor::operator($userId, 'worker'), $nodeId, $productId, $qty, 'worker loss');
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Waits for "go", then records a loss. Reports RESULT ok|<code>
        // (not ERROR, so a rejected decrement is an expected outcome).
        case 'loss-on-go': {
            [, , $userId, $nodeId, $productId, $qty] = $argv;
            $say('READY');
            fgets(STDIN);
            try {
                $app->make(RecordInventoryOperation::class)->recordLoss(Actor::operator($userId, 'worker'), $nodeId, $productId, $qty, 'race');
                $say('RESULT ok');
            } catch (DomainException $e) {
                $say('RESULT '.$e->errorCode());
            }
            break;
        }

        // Rebuilds the stock projection and keeps the transaction (and its table locks) open.
        case 'hold-rebuild': {
            [, , $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $say, $holdMs): void {
                $app->make(StockProjection::class)->rebuild();
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Confirms a DRAFT sale (optionally with one cash payment) and holds the transaction.
        case 'hold-confirm-sale': {
            [, , $userId, $saleId, $holdMs] = $argv;
            $cash = $argv[5] ?? null;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $saleId, $holdMs, $cash, $say): void {
                $app->make(ConfirmSale::class)->execute(Actor::operator($userId, 'worker'), $saleId, $cash === null ? [] : [['method' => 'CASH', 'amount' => $cash]]);
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Confirms a DRAFT credit sale with an OWNER credit-limit override and holds the transaction (customer credit row locked).
        case 'hold-confirm-override': {
            [, , $userId, $saleId, $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $saleId, $holdMs, $say): void {
                $app->make(ConfirmSaleWithCreditOverride::class)->execute(Actor::operator($userId, 'worker'), $saleId, 'worker override: pelanggan lama');
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Records a payment and holds the transaction (sale row locked).
        case 'hold-payment': {
            [, , $userId, $saleId, $amount, $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $saleId, $amount, $holdMs, $say): void {
                $app->make(RecordPayment::class)->execute(Actor::operator($userId, 'worker'), $saleId, ['method' => 'CASH', 'amount' => $amount]);
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Reverses a sale (with its payments) and holds the transaction.
        case 'hold-reverse-sale': {
            [, , $userId, $saleId, $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $saleId, $holdMs, $say): void {
                $app->make(ReverseSale::class)->execute(Actor::operator($userId, 'worker'), $saleId, 'worker reversal', true);
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Reverses one payment and holds the transaction (sale + payment locked).
        case 'hold-reverse-payment': {
            [, , $userId, $paymentId, $holdMs] = $argv;
            $app->make(TransactionManager::class)->run(function () use ($app, $userId, $paymentId, $holdMs, $say): void {
                $app->make(ReversePayment::class)->execute(Actor::operator($userId, 'worker'), $paymentId, 'worker reversal');
                $say('ACQUIRED');
                usleep((int) $holdMs * 1000);
            });
            $say('DONE');
            break;
        }

        // Waits for "go", then sells qty × price to a walk-in, paid in cash. Reports RESULT ok|<code>.
        case 'sale-on-go': {
            [, , $userId, $nodeId, $productId, $qty, $price] = $argv;
            $say('READY');
            fgets(STDIN);
            try {
                $total = \App\Domain\Shared\Money\Money::of($price)->multipliedBy($qty)->toStorage();
                $app->make(CreateSale::class)->execute(Actor::operator($userId, 'worker'), null, $nodeId,
                    [['product_id' => $productId, 'quantity' => $qty, 'unit_price' => $price]], null, null, true, [['method' => 'CASH', 'amount' => $total]]);
                $say('RESULT ok');
            } catch (DomainException $e) {
                $say('RESULT '.$e->errorCode());
            }
            break;
        }

        default:
            $say("ERROR unknown scenario {$scenario}");
            exit(2);
    }
} catch (DomainException $e) {
    $say('ERROR '.$e->errorCode());
    exit(1);
} catch (Throwable $e) {
    $say('ERROR '.$e::class.': '.$e->getMessage());
    exit(1);
}
