<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ReflectionEnum;
use Tests\Feature\Sales\SalesTestCase;

/**
 * A5-B Part 26 #20 — the contract the Flutter app relies on (docs/API_CONTRACT_V1.md):
 * response shapes of the core mobile flow, the error envelope and its HTTP
 * statuses, idempotency headers, pagination meta, and that every error code
 * the backend can emit is documented in docs/API_ERROR_CODES.md.
 */
final class MobileApiContractTest extends SalesTestCase
{
    private const PAGE_META = ['page', 'per_page', 'total', 'last_page'];

    /** `details` is present only when there is something to say (documented as optional). */
    private const ERROR = ['error' => ['code', 'message']];

    public function test_login_contract(): void
    {
        User::factory()->create(['email' => 'kasir@dgway.test', 'password' => Hash::make('rahasia-sekali-1')]);
        $this->postJson('/api/v1/auth/login', ['email' => 'kasir@dgway.test', 'password' => 'rahasia-sekali-1', 'device_name' => 'pixel-7'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at', 'operator' => ['id', 'name', 'email', 'role']]]);
        $this->postJson('/api/v1/auth/login', ['email' => 'kasir@dgway.test', 'password' => 'salah-sekali-1', 'device_name' => 'pixel-7'])
            ->assertStatus(401)->assertJsonStructure(self::ERROR)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_core_mobile_flow_contract(): void
    {
        $config = $this->get_('config')->assertOk()->assertJsonStructure(['data' => [
            'api_version', 'currency', 'business_timezone', 'business_date', 'server_time_utc', 'default_node' => ['id', 'code', 'name'],
            'walk_in_customer', 'pagination' => ['default_per_page', 'max_per_page'], 'payment_methods', 'stock_buckets', 'idempotency' => ['header', 'format', 'retention_days'],
        ]])->json('data');
        $node = $config['default_node']['id'];

        $this->get_('products?per_page=10')->assertOk()->assertJsonStructure(['data' => [['id', 'sku', 'name', 'base_uom', 'is_active', 'version']], 'meta' => self::PAGE_META]);

        $gr = $this->cmd('POST', 'goods-receipts', ['supplier_id' => $this->supplier, 'node_id' => $node, 'lines' => [
            $this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']]),
        ]])->assertCreated()->assertJsonStructure(['data' => ['id', 'number', 'status', 'version', 'total_cost', 'lines' => [['id', 'product_id', 'received_quantity', 'unit_cost', 'line_cost', 'dispositions']]]])->json('data');
        $key = (string) Str::uuid();
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post", ['expected_version' => $gr['version']], $key)->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post", ['expected_version' => $gr['version']], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->get_('lots')->assertOk()->assertJsonStructure(['data' => [['id', 'number', 'origin', 'status', 'product' => ['id', 'sku', 'name'], 'node' => ['id', 'code'], 'uom',
            'original_quantity', 'remaining' => ['on_hand', 'available', 'quarantine', 'return_pending'], 'supplier', 'goods_receipt', 'business_date', 'received_at',
            'manufactured_date', 'best_before_date', 'provenance']], 'meta' => self::PAGE_META]);

        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $sale = $this->cmd('POST', 'sales', ['customer_id' => $abc, 'node_id' => $node, 'confirm' => true,
            'lines' => [['product_id' => $this->egg, 'quantity' => '50', 'unit_price' => '25500']], 'payments' => [['method' => 'BANK_TRANSFER', 'amount' => '775000']]])
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'number', 'status', 'payment_status', 'version', 'customer', 'is_walk_in', 'node_id', 'business_date', 'currency',
                'subtotal', 'discount_total', 'grand_total', 'paid', 'credited', 'outstanding', 'payment_term_days', 'due_date', 'is_overdue',
                'lines' => [['id', 'line_no', 'product_id', 'uom', 'quantity', 'unit_price', 'total', 'returned_quantity', 'returnable_quantity']]]])
            ->assertJsonPath('data.outstanding', '500000.0000')->json('data');
        // money and quantities are decimal STRINGS with 4 places, never floats
        $this->assertSame(['1275000.0000', '775000.0000', '50.0000'], [$sale['grand_total'], $sale['paid'], $sale['lines'][0]['quantity']]);

        $this->cmd('POST', "sales/{$sale['id']}/payments", ['method' => 'QRIS', 'amount' => '100000', 'expected_version' => $sale['version']])
            ->assertCreated()->assertJsonStructure(['data' => ['id', 'number', 'status', 'method', 'amount', 'business_date', 'sale' => ['id', 'outstanding']]]);
        $this->get_('receivables/customers')->assertOk()->assertJsonStructure(['data' => [['customer' => ['id', 'code', 'name'], 'outstanding', 'overdue', 'available_credit']], 'meta' => self::PAGE_META]);
        $this->get_('dashboard')->assertOk()->assertJsonStructure(['data' => ['business_date', 'timezone', 'currency', 'sales' => ['count', 'total'],
            'payments' => ['total', 'by_method' => ['cash', 'bank_transfer', 'qris']], 'receivables' => ['total', 'due_today', 'overdue'], 'purchasing', 'inventory']]);
        $this->get_('activity')->assertOk()->assertJsonStructure(['data' => [['type', 'occurred_at', 'document' => ['type', 'id', 'number'], 'amount']], 'meta' => self::PAGE_META, 'summary' => ['receivable_change']]);
        $this->get_('search?q=telur')->assertOk()->assertJsonStructure(['data' => [['type', 'id', 'code', 'label', 'subtitle', 'active']], 'meta' => self::PAGE_META]);
        $this->getJson('/api/v1/health')->assertOk()->assertHeader('X-Request-Id');
    }

    public function test_error_envelope_and_status_contract(): void
    {
        $this->stockUp('10');
        $this->cmd('POST', 'sales', ['node_id' => $this->node, 'lines' => []])->assertStatus(422)->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields']]])
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->get_('sales/'.Str::uuid())->assertStatus(404)->assertJsonStructure(self::ERROR)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->sell(null, '50', '27000', true, [$this->cash('1350000')])->assertStatus(409)->assertJsonStructure(self::ERROR)
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->postJson('/api/v1/sales', [])->assertStatus(428)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->postJson('/api/v1/sales', [], ['Idempotency-Key' => 'x'])->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
        $key = (string) Str::uuid();
        $this->cmd('POST', 'categories', ['code' => 'A1', 'name' => 'A'], $key)->assertCreated();
        $this->cmd('POST', 'categories', ['code' => 'A2', 'name' => 'B'], $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $draft = $this->sell(null, '1', '27000', false)->json('data');
        $this->cmd('POST', "sales/{$draft['id']}/cancel", ['reason' => 'x', 'expected_version' => $draft['version'] + 5])->assertStatus(409)
            ->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        config(['dgway.authorization.role_permissions.OWNER' => []]);
        $this->cmd('POST', "sales/{$draft['id']}/confirm-with-credit-override", ['reason' => 'tidak berwenang'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer invalid')->getJson('/api/v1/sales')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /** Every error code reachable from app/ is documented; a new code fails this test until docs/API_ERROR_CODES.md lists it. */
    public function test_every_error_code_is_documented(): void
    {
        $root = dirname(__DIR__, 3);
        $doc = (string) file_get_contents("{$root}/docs/API_ERROR_CODES.md");
        $enumValues = [];
        $code = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/app")) as $f) {
            if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), '/app/Console/')) { // console output (env names) is not an API error
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            $code .= $src;
            if (preg_match('/^enum\s+(\w+)/m', $src, $m) && preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
                foreach ((new ReflectionEnum($ns[1].'\\'.$m[1]))->getCases() as $case) {
                    $enumValues[] = (string) ($case->getBackingValue() ?? $case->getName());
                }
            }
        }
        preg_match_all("/'([A-Z][A-Z0-9]+(?:_[A-Z0-9]+)+)'/", $code, $all);
        $notErrors = [...$enumValues,
            // activity feed types, document/source types written in UPPER case, movement & bucket names used as data
            'PURCHASE_ORDER', 'GOODS_RECEIPT', 'CUSTOMER_RETURN', 'PAYMENT_REVERSAL', 'SALE_REVERSAL', 'GOODS_RECEIPT_REVERSAL', 'DIRECT_PURCHASE', 'PARTIALLY_PAID',
            // read-model labels, not errors
            'UNATTRIBUTED_LEGACY', 'DUE_TODAY', 'NOT_YET_DUE',
            // A5-C print document types / scan match labels (data, not errors)
            'LOT_LABEL', 'LOT_NUMBER',
        ];
        $codes = array_values(array_diff(array_unique($all[1]), $notErrors));
        $this->assertGreaterThan(100, count($codes));
        $missing = array_values(array_filter($codes, fn ($c) => ! str_contains($doc, "`{$c}`")));
        $this->assertSame([], $missing, 'Undocumented error codes: '.implode(', ', $missing));
    }

    /** A5-C: the route table and docs/API_CONTRACT_V1.md never drift apart (every endpoint documented, nothing documented that does not exist). */
    public function test_every_api_route_is_documented_in_the_contract(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/API_CONTRACT_V1.md');
        preg_match_all('/^\| (GET|POST|PATCH|PUT|DELETE) \| `([^`]+)` \|/m', $doc, $m, PREG_SET_ORDER);
        $documented = array_map(fn ($x) => $x[1].' '.$x[2], $m);
        $actual = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' '.substr($route->uri(), strlen('api/v1/'));
            }
        }
        sort($actual);
        sort($documented);
        $this->assertSame([], array_values(array_diff($actual, $documented)), 'routes missing from the endpoint catalog of API_CONTRACT_V1.md');
        $this->assertSame([], array_values(array_diff($documented, $actual)), 'documented endpoints that do not exist');
    }
}
