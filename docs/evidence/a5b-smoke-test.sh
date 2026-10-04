#!/usr/bin/env bash
# Phase A5-B live HTTP smoke test (Part 25) against a running server and a FRESH database.
# BOOTSTRAP → CONFIG → MASTER DATA → PO → GR (lot) → RETAIL SALE → B2B SALE → LOT TRACE → DASHBOARD → AUDIT
#
# Preparation (fresh DB, owner password via STDIN, never a default):
#   createdb dgway_smoke; export DB_DATABASE=dgway_smoke
#   php artisan migrate --force
#   printf '%s' "$PASSWORD" | php artisan dgway:bootstrap --owner-email="$EMAIL" --owner-name=Owner --password-stdin
#   php artisan serve --port=8765 &
# Usage: BASE=http://127.0.0.1:8765/api/v1 EMAIL=… PASSWORD=… PGPASSWORD=… DB_DATABASE=dgway_smoke ./a5b-smoke-test.sh
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8765/api/v1}
DBNAME=${DB_DATABASE:-dgway_smoke}

j() { python3 -c "import sys,json; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }
call() { # method path key body
  local m=$1 p=$2 k=$3 b=${4:-}
  curl -s -D /tmp/smoke.headers -o /tmp/smoke.body -w '%{http_code}' -X "$m" "$BASE/$p" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' -H 'X-Request-Id: smoke-a5b-'"$RANDOM$RANDOM" \
    ${k:+-H "Idempotency-Key: $k"} ${b:+-d "$b"}
}
show() { printf '%-64s HTTP %s  %s\n' "$1" "$2" "$(head -c 120 /tmp/smoke.body)"; }
field() { j "$1" < /tmp/smoke.body; }
replayed() { grep -i '^idempotent-replayed' /tmp/smoke.headers | tr -d '\r' || echo "(not replayed)"; }
stock() { call GET "inventory/current?product_id=$TLR&node_id=$NODE" "" >/dev/null; j "'available=' + d['data']['available'] + ' quarantine=' + d['data']['quarantine'] + ' ledger_on_hand=' + d['data']['ledger_on_hand']" < /tmp/smoke.body; }
psqlq() { psql -h 127.0.0.1 -U dgway -d "$DBNAME" -v ON_ERROR_STOP=1 -Atc "$1"; }

echo "== READINESS (no auth) =="
curl -s "$BASE/health/ready"; echo

echo; echo "== LOGIN =="
TOKEN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device_name\":\"smoke-a5b\"}" | j "d['data']['token']")
echo "login ok (token redacted)"

echo; echo "== OPERATIONAL DEFAULTS (the app never hard-codes the node UUID) =="
s=$(call GET config ""); show "GET config" $s
NODE=$(field "d['data']['default_node']['id']"); WALKIN=$(field "d['data']['walk_in_customer']['id']")
echo "  currency=$(field "d['data']['currency']") tz=$(field "d['data']['business_timezone']") business_date=$(field "d['data']['business_date']") node=$(field "d['data']['default_node']['code']") walk_in=$(field "d['data']['walk_in_customer']['code']") page=$(field "d['data']['pagination']")"

echo; echo "== MASTER DATA =="
s=$(call POST business-partners k-a5b-sup-bdg-00001 '{"code":"sup-peternak-bandung","name":"Peternak Bandung","party_type":"ORGANIZATION","phone":"0812-1111-2222","supplier":{"term_days":0}}'); show "supplier Peternak Bandung" $s; SUP=$(field "d['data']['id']")
s=$(call POST business-partners k-a5b-cus-abc-00001 '{"code":"cus-catering-abc","name":"Catering ABC","party_type":"ORGANIZATION","phone":"0812-3333-4444","customer":{"customer_type":"CATERING","term_days":7,"credit_limit":"5000000"}}'); show "customer Catering ABC (tempo 7 hari, limit 5jt)" $s; ABC=$(field "d['data']['id']")
s=$(call POST categories k-a5b-cat-egg-00001 '{"code":"egg","name":"Telur"}'); show "category EGG" $s; CAT=$(field "d['data']['id']")
s=$(call POST products k-a5b-prod-tlr-0001 "{\"sku\":\"telur-std\",\"name\":\"Telur Ayam Standar\",\"category_id\":\"$CAT\",\"base_uom\":\"KG\"}"); show "product TELUR-STD (KG)" $s; TLR=$(field "d['data']['id']")

echo; echo "== PO 150 KG × 23.000 → GR 150 KG (140 ACCEPT, 10 LOSS) → LOT =="
s=$(call POST purchase-orders k-a5b-po-create-001 "{\"supplier_id\":\"$SUP\",\"node_id\":\"$NODE\",\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"150\",\"unit_cost\":\"23000\"}]}"); show "POST purchase-orders" $s
PO=$(field "d['data']['id']"); POL=$(field "d['data']['lines'][0]['id']"); echo "  $(field "d['data']['number']") total=$(field "d['data']['total_amount']")"
s=$(call POST "purchase-orders/$PO/confirm" k-a5b-po-confirm-01); show "POST purchase-orders/{id}/confirm" $s
s=$(call POST goods-receipts k-a5b-gr-create-001 "{\"supplier_id\":\"$SUP\",\"node_id\":\"$NODE\",\"purchase_order_id\":\"$PO\",\"lines\":[{\"product_id\":\"$TLR\",\"purchase_order_line_id\":\"$POL\",\"quantity\":\"150\",\"unit_cost\":\"23000\",\"best_before_date\":\"2026-10-24\",\"dispositions\":[{\"type\":\"ACCEPT\",\"quantity\":\"140\"},{\"type\":\"LOSS\",\"quantity\":\"10\",\"reason\":\"pecah saat bongkar\"}]}]}"); show "POST goods-receipts (from PO)" $s
GR=$(field "d['data']['id']"); echo "  draft lots: $(psqlq "select count(*) from inventory_lots where goods_receipt_id='$GR'") (a draft creates no lot)"
s=$(call POST "goods-receipts/$GR/post" k-a5b-gr-post-00001); show "POST goods-receipts/{id}/post" $s
s=$(call POST "goods-receipts/$GR/post" k-a5b-gr-post-00001); show "RETRY post (same key)" $s; echo "  $(replayed); lots for GR: $(psqlq "select count(*) from inventory_lots where goods_receipt_id='$GR'")"
s=$(call GET "lots?goods_receipt_id=$GR" ""); show "GET lots?goods_receipt_id" $s
LOT=$(field "d['data'][0]['id']"); LOTNO=$(field "d['data'][0]['number']")
echo "  lot=$LOTNO origin=$(field "d['data'][0]['origin']") status=$(field "d['data'][0]['status']") original=$(field "d['data'][0]['original_quantity']") remaining=$(field "d['data'][0]['remaining']") supplier=$(field "d['data'][0]['supplier']['name']") best_before=$(field "d['data'][0]['best_before_date']")"
echo "  STOCK: $(stock)"

echo; echo "== RETAIL (walk-in) 30 KG × 27.000 = 810.000, CASH full =="
s=$(call POST sales k-a5b-sale-retail-1 "{\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"30\",\"unit_price\":\"27000\"}],\"payments\":[{\"method\":\"CASH\",\"amount\":\"810000\"}]}"); show "POST sales (walk-in, confirm + cash)" $s
SR=$(field "d['data']['id']"); echo "  $(field "d['data']['number']") status=$(field "d['data']['status']") total=$(field "d['data']['grand_total']") outstanding=$(field "d['data']['outstanding']")"
echo "  STOCK: $(stock)   (expected available 110)"

echo; echo "== B2B Catering ABC 50 KG × 25.500 = 1.275.000, paid 775.000 =="
s=$(call POST sales k-a5b-sale-b2b-0001 "{\"customer_id\":\"$ABC\",\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"50\",\"unit_price\":\"25500\"}],\"payments\":[{\"method\":\"BANK_TRANSFER\",\"amount\":\"775000\",\"reference\":\"TRF-BCA-A5B\"}]}"); show "POST sales (credit customer, confirm + 775.000)" $s
SB=$(field "d['data']['id']"); SBN=$(field "d['data']['number']")
echo "  $SBN status=$(field "d['data']['status']") total=$(field "d['data']['grand_total']") paid=$(field "d['data']['paid']") outstanding=$(field "d['data']['outstanding']") due=$(field "d['data']['due_date']")"
s=$(call POST sales k-a5b-sale-b2b-0001 "{\"customer_id\":\"$ABC\",\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"50\",\"unit_price\":\"25500\"}],\"payments\":[{\"method\":\"BANK_TRANSFER\",\"amount\":\"775000\",\"reference\":\"TRF-BCA-A5B\"}]}"); show "RETRY sale (same key)" $s
echo "  $(replayed); sales=$(psqlq 'select count(*) from sales') SALE movements=$(psqlq "select count(*) from stock_movements where movement_type='SALE'")"
echo "  STOCK: $(stock)   (expected available 60)"

echo; echo "== LOT: remaining, movements, allocation, customer destination =="
s=$(call GET "lots/$LOTNO" ""); show "GET lots/{number}" $s
echo "  status=$(field "d['data']['status']") remaining=$(field "d['data']['remaining']") dispositions=$(field "[(x['type'], x['quantity']) for x in d['data']['dispositions']]")"
s=$(call GET "lots/$LOT/movements" ""); show "GET lots/{id}/movements" $s
j "'\n'.join('  #%s %-8s %-11s %10s  %s' % (m['sequence'], m['movement_type'], m['bucket'], m['signed_quantity'], m['source']['type']) for m in reversed(d['data']))" < /tmp/smoke.body
echo "  allocation (SQL): $(psqlq "select string_agg(s.number || ' ' || m.quantity || ' from ' || l.number, ' | ' order by m.sequence) from stock_movements m join sales s on s.id=m.source_document_id join inventory_lots l on l.id=m.lot_id where m.movement_type='SALE'")"
s=$(call GET "lots/$LOT/destinations" ""); show "GET lots/{id}/destinations" $s
j "'\n'.join('  %s %s customer=%s sold=%s returned=%s net=%s' % (r['sale_number'], r['business_date'], (r['customer'] or {}).get('name', 'walk-in'), r['sold'], r['returned'], r['net']) for r in d['data'])" < /tmp/smoke.body
s=$(call GET "inventory/lot-balances?product_id=$TLR" ""); show "GET inventory/lot-balances" $s
j "'\n'.join('  %s %s %s %s' % (r['lot_number'], r['bucket'], r['quantity'], r['uom']) for r in d['data'])" < /tmp/smoke.body

echo; echo "== PAYMENT + RECEIVABLE =="
s=$(call GET "payments?sale_id=$SB" ""); show "GET payments?sale_id" $s; echo "  $(field "[(p['number'], p['method'], p['amount']) for p in d['data']]")"
s=$(call GET receivables/customers ""); show "GET receivables/customers" $s
j "'\n'.join('  %s %s owes %s (available credit %s)' % (r['customer']['code'], r['customer']['name'], r['outstanding'], r['available_credit']) for r in d['data'])" < /tmp/smoke.body

echo; echo "== DASHBOARD + ACTIVITY + SEARCH =="
s=$(call GET dashboard ""); show "GET dashboard" $s
j "'  sales: %s / %s | payments: %s (cash %s, transfer %s, qris %s) | receivable: total %s due %s overdue %s\n  purchasing: PO %s %s, received %s | inventory: %s, loss today %s, active lots %s' % (d['data']['sales']['count'], d['data']['sales']['total'], d['data']['payments']['total'], d['data']['payments']['by_method']['cash']['received'], d['data']['payments']['by_method']['bank_transfer']['received'], d['data']['payments']['by_method']['qris']['received'], d['data']['receivables']['total'], d['data']['receivables']['due_today'], d['data']['receivables']['overdue'], d['data']['purchasing']['purchase_orders']['count'], d['data']['purchasing']['purchase_orders']['total'], d['data']['purchasing']['received_quantity'], d['data']['inventory']['stock_by_uom'], d['data']['inventory']['losses_on_date'], d['data']['inventory']['active_lots_now'])" < /tmp/smoke.body
s=$(call GET "activity?per_page=50" ""); show "GET activity" $s
j "'\n'.join('  %-15s %-16s %12s %s' % (a['type'], a['document']['number'], a['amount'] or '', (a['counterparty'] or {}).get('name', '')) for a in d['data'])" < /tmp/smoke.body
echo "  receivable_change: $(field "d['summary']['receivable_change']")"
s=$(call GET "search?q=0812-3333" ""); show "GET search?q=0812-3333 (phone, local format)" $s; echo "  $(field "[(r['type'], r['code']) for r in d['data']]")"
s=$(call GET "search?q=$LOTNO&type=documents" ""); show "GET search?q=<lot number>" $s; echo "  $(field "[(r['type'], r['code']) for r in d['data']]")"

echo; echo "== AUDIT + TRACE + INTEGRITY =="
psqlq "select action, count(*) from audit_logs group by action order by action" | sed 's/^/  /'
echo "  audit rows carrying request_id: $(psqlq "select count(*) from audit_logs where context ? 'request_id'") / $(psqlq 'select count(*) from audit_logs where actor_user_id is not null')"
s=$(call GET inventory/integrity ""); show "GET inventory/integrity" $s
echo "  php artisan dgway:trace $LOTNO:"
php artisan dgway:trace "$LOTNO" | sed 's/^/    /'
php artisan dgway:inventory:verify | sed 's/^/  /'

curl -s -o /dev/null -X POST "$BASE/auth/logout" -H "Authorization: Bearer $TOKEN"
echo; echo "logout ok"
