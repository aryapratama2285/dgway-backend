#!/usr/bin/env bash
# Phase A3 live HTTP smoke test against a running server (php artisan serve).
# Scenario A: direct purchase 150 KG @ Rp23.000, 140 ACCEPT + 10 LOSS.
# Scenario B: retry with the same Idempotency-Key → stock stays 140.
# Scenario C: direct UPDATE / DELETE on the ledger is refused by PostgreSQL.
# Usage: BASE=http://127.0.0.1:8765/api/v1 EMAIL=… PASSWORD=… PGPASSWORD=… ./a3-smoke-test.sh
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8765/api/v1}

j() { python3 -c "import sys,json; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }
call() { # method path key body
  local m=$1 p=$2 k=$3 b=${4:-}
  curl -s -D /tmp/smoke.headers -o /tmp/smoke.body -w '%{http_code}' -X "$m" "$BASE/$p" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    ${k:+-H "Idempotency-Key: $k"} ${b:+-d "$b"}
}
show() { printf '%-60s HTTP %s  %s\n' "$1" "$2" "$(head -c 150 /tmp/smoke.body)"; }
replayed() { grep -i '^idempotent-replayed' /tmp/smoke.headers | tr -d '\r' || echo "(not replayed)"; }
stock() { call GET "inventory/current?product_id=$TLR&node_id=$NODE" "" >/dev/null; j "'available=' + d['data']['available'] + ' quarantine=' + d['data']['quarantine'] + ' return_pending=' + d['data']['return_pending'] + ' ledger_on_hand=' + d['data']['ledger_on_hand']" < /tmp/smoke.body; }
psqlq() { psql -h 127.0.0.1 -U dgway -d dgway -v ON_ERROR_STOP=1 -Atc "$1"; }

TOKEN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device_name\":\"smoke-a3\"}" | j "d['data']['token']")
echo "login ok (token redacted)"

echo; echo "== setup: supplier Bandung, product TELUR-STD, node DG-WAY-PRIMARY =="
s=$(call POST business-partners k-a3-sup-bandung-01 '{"code":"sup-bandung","name":"Supplier Telur Bandung","party_type":"ORGANIZATION","supplier":{"term_days":0}}'); show "POST business-partners SUP-BANDUNG (supplier)" $s
SUP=$(j "d['data']['id']" < /tmp/smoke.body)
s=$(call POST categories k-a3-cat-egg-000001 '{"code":"egg","name":"Telur"}'); show "POST categories EGG" $s
CAT=$(j "d['data']['id']" < /tmp/smoke.body)
s=$(call POST products k-a3-prod-telur-std "{\"sku\":\"telur-std\",\"name\":\"Telur Ayam Standar\",\"category_id\":\"$CAT\",\"base_uom\":\"KG\"}"); show "POST products TELUR-STD (KG)" $s
TLR=$(j "d['data']['id']" < /tmp/smoke.body)
s=$(call GET "nodes?q=DG-WAY-PRIMARY" ""); show "GET nodes DG-WAY-PRIMARY (from dgway:network:bootstrap)" $s
NODE=$(j "d['data'][0]['id']" < /tmp/smoke.body)
echo "  node capabilities: $(j "d['data'][0]['capabilities']" < /tmp/smoke.body)"

echo; echo "== Scenario A: direct purchase 150 KG @ 23000, 140 ACCEPT + 10 LOSS =="
GR_BODY="{\"supplier_id\":\"$SUP\",\"node_id\":\"$NODE\",\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"150\",\"uom\":\"KG\",\"unit_cost\":\"23000\",\"dispositions\":[{\"type\":\"ACCEPT\",\"quantity\":\"140\"},{\"type\":\"LOSS\",\"quantity\":\"10\",\"reason\":\"pecah di perjalanan\"}]}]}"
s=$(call POST goods-receipts k-a3-gr-create-00001 "$GR_BODY"); show "POST goods-receipts (DRAFT)" $s
GR=$(j "d['data']['id']" < /tmp/smoke.body)
echo "  number=$(j "d['data']['number']" < /tmp/smoke.body) type=$(j "d['data']['type']" < /tmp/smoke.body) line_cost=$(j "d['data']['lines'][0]['line_cost']" < /tmp/smoke.body) total_cost=$(j "d['data']['total_cost']" < /tmp/smoke.body)"
echo "  stock before posting: $(stock)"
s=$(call POST "goods-receipts/$GR/post" k-a3-gr-post-000001); show "POST goods-receipts/{id}/post" $s
echo "  status=$(j "d['data']['status']" < /tmp/smoke.body) yield=$(j "d['data']['yield_analysis'][0]" < /tmp/smoke.body)"
echo "  stock after posting:  $(stock)"
s=$(call GET "inventory/movements?source_document_id=$GR" ""); show "GET inventory/movements (ledger trace)" $s
j "'\n'.join('  #%s %-8s %-11s %+.4f  cost=%s corr=%s' % (m['sequence'], m['movement_type'], m['bucket'], float(m['signed_quantity']), m['unit_cost'], m['correlation_id'][:8]) for m in reversed(d['data']))" < /tmp/smoke.body
echo "  loss recorded in ledger: $(j "sum(float(m['quantity']) for m in d['data'] if m['movement_type']=='LOSS')" < /tmp/smoke.body) KG"

echo; echo "== Scenario B: client retries with the SAME Idempotency-Keys =="
s=$(call POST goods-receipts k-a3-gr-create-00001 "$GR_BODY"); show "RETRY POST goods-receipts (same key)" $s
echo "  $(replayed); same id: $([ "$(j "d['data']['id']" < /tmp/smoke.body)" = "$GR" ] && echo yes || echo NO)"
s=$(call POST "goods-receipts/$GR/post" k-a3-gr-post-000001); show "RETRY POST goods-receipts/{id}/post (same key)" $s
echo "  $(replayed)"
echo "  stock after retries:  $(stock)"
s=$(call POST "goods-receipts/$GR/post" k-a3-gr-post-000002); show "POST .../post again with a NEW key (must be refused)" $s
echo "  goods_receipts rows: $(psqlq 'select count(*) from goods_receipts')  stock_movements rows: $(psqlq 'select count(*) from stock_movements')"
s=$(call POST goods-receipts "" "$GR_BODY"); show "POST goods-receipts without Idempotency-Key" $s

echo; echo "== extra: no negative stock, integrity =="
s=$(call POST inventory/losses k-a3-loss-too-much1 "{\"node_id\":\"$NODE\",\"product_id\":\"$TLR\",\"quantity\":\"141\",\"reason\":\"uji\"}"); show "POST inventory/losses 141 KG (only 140 available)" $s
s=$(call GET inventory/integrity ""); show "GET inventory/integrity" $s

echo; echo "== Scenario C: direct SQL against the ledger (bypassing the app) =="
set +e
echo "  UPDATE stock_movements SET quantity = 999:"; psqlq "UPDATE stock_movements SET quantity = 999" 2>&1 | sed 's/^/    /'
echo "  DELETE FROM stock_movements:";            psqlq "DELETE FROM stock_movements" 2>&1 | sed 's/^/    /'
echo "  UPDATE stock_balances SET quantity = -1:"; psqlq "UPDATE stock_balances SET quantity = -1" 2>&1 | sed 's/^/    /'
echo "  UPDATE goods_receipt_lines (posted):";      psqlq "UPDATE goods_receipt_lines SET received_quantity = 151" 2>&1 | sed 's/^/    /'
set -e
echo "  ledger after attempts: rows=$(psqlq 'select count(*) from stock_movements') sum(signed)=$(psqlq 'select sum(signed_quantity) from stock_movements')"
echo "  stock: $(stock)"

curl -s -o /dev/null -X POST "$BASE/auth/logout" -H "Authorization: Bearer $TOKEN"
echo; echo "logout ok"
