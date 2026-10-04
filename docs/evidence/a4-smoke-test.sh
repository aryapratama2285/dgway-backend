#!/usr/bin/env bash
# Phase A4 live HTTP smoke test — the full Owner Operating flow against a running server.
# LOGIN → MASTER DATA → PURCHASE → RECEIVE → STOCK → SALE → PAYMENT → STOCK → RECEIVABLE → OWNER QUERY
# plus Scenarios A–F of the A4 brief.
# Usage: BASE=http://127.0.0.1:8765/api/v1 EMAIL=… PASSWORD=… PGPASSWORD=… ./a4-smoke-test.sh
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8765/api/v1}

j() { python3 -c "import sys,json; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }
call() { # method path key body
  local m=$1 p=$2 k=$3 b=${4:-}
  curl -s -D /tmp/smoke.headers -o /tmp/smoke.body -w '%{http_code}' -X "$m" "$BASE/$p" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    ${k:+-H "Idempotency-Key: $k"} ${b:+-d "$b"}
}
show() { printf '%-62s HTTP %s  %s\n' "$1" "$2" "$(head -c 140 /tmp/smoke.body)"; }
field() { j "$1" < /tmp/smoke.body; }
replayed() { grep -i '^idempotent-replayed' /tmp/smoke.headers | tr -d '\r' || echo "(not replayed)"; }
stock() { call GET "inventory/current?product_id=$TLR&node_id=$NODE" "" >/dev/null; j "'available=' + d['data']['available'] + ' quarantine=' + d['data']['quarantine'] + ' ledger_on_hand=' + d['data']['ledger_on_hand']" < /tmp/smoke.body; }
psqlq() { psql -h 127.0.0.1 -U dgway -d dgway -v ON_ERROR_STOP=1 -Atc "$1"; }

echo "== LOGIN =="
TOKEN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device_name\":\"smoke-a4\"}" | j "d['data']['token']")
echo "login ok (token redacted)"

echo; echo "== MASTER DATA =="
s=$(call POST business-partners k-a4-sup-bdg-000001 '{"code":"sup-bandung","name":"Supplier Telur Bandung","party_type":"ORGANIZATION","supplier":{"term_days":0}}'); show "supplier SUP-BANDUNG" $s; SUP=$(field "d['data']['id']")
s=$(call POST business-partners k-a4-cus-abc-000001 '{"code":"cus-catering-abc","name":"Catering ABC","party_type":"ORGANIZATION","phone":"0812-3333-4444","customer":{"customer_type":"CATERING","term_days":7,"credit_limit":"5000000"}}'); show "customer CUS-CATERING-ABC (tempo 7 hari, limit 5jt)" $s; ABC=$(field "d['data']['id']")
s=$(call POST categories k-a4-cat-egg-000001 '{"code":"egg","name":"Telur"}'); show "category EGG" $s; CAT=$(field "d['data']['id']")
s=$(call POST products k-a4-prod-tlr-std01 "{\"sku\":\"telur-std\",\"name\":\"Telur Ayam Standar\",\"category_id\":\"$CAT\",\"base_uom\":\"KG\"}"); show "product TELUR-STD (KG)" $s; TLR=$(field "d['data']['id']")
s=$(call GET "nodes?q=DG-WAY-PRIMARY" ""); show "node DG-WAY-PRIMARY (bootstrap)" $s; NODE=$(field "d['data'][0]['id']")

echo; echo "== PURCHASE + RECEIVE: 150 KG @ 23.000, 140 accepted, 10 loss =="
s=$(call POST goods-receipts k-a4-gr-create-00001 "{\"supplier_id\":\"$SUP\",\"node_id\":\"$NODE\",\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"150\",\"unit_cost\":\"23000\",\"dispositions\":[{\"type\":\"ACCEPT\",\"quantity\":\"140\"},{\"type\":\"LOSS\",\"quantity\":\"10\",\"reason\":\"pecah\"}]}]}"); show "POST goods-receipts" $s; GR=$(field "d['data']['id']")
s=$(call POST "goods-receipts/$GR/post" k-a4-gr-post-0000001); show "POST goods-receipts/{id}/post" $s
echo "  total_cost=$(field "d['data']['total_cost']")   STOCK: $(stock)"

echo; echo "== Scenario A — walk-in cash sale 30 KG × 26.000 = 780.000, CASH 780.000 =="
s=$(call POST sales k-a4-sale-a-0000001 "{\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"30\",\"unit_price\":\"26000\"}],\"payments\":[{\"method\":\"CASH\",\"amount\":\"780000\"}]}"); show "POST sales (walk-in, confirm + pay)" $s
SA=$(field "d['data']['id']")
echo "  $(field "d['data']['number']") status=$(field "d['data']['status']") grand_total=$(field "d['data']['grand_total']") paid=$(field "d['data']['paid']") outstanding=$(field "d['data']['outstanding']")"
echo "  STOCK: $(stock)"

echo; echo "== Scenario B — B2B Catering ABC 100 KG × 25.000 = 2.500.000, pays 1.500.000 then 700.000 =="
s=$(call POST sales k-a4-sale-b-0000001 "{\"customer_id\":\"$ABC\",\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"100\",\"unit_price\":\"25000\"}],\"payments\":[{\"method\":\"BANK_TRANSFER\",\"amount\":\"1500000\",\"reference\":\"TRF-BCA-001\"}]}"); show "POST sales (credit customer, confirm + 1.5jt)" $s
SB=$(field "d['data']['id']")
echo "  $(field "d['data']['number']") status=$(field "d['data']['status']") grand_total=$(field "d['data']['grand_total']") paid=$(field "d['data']['paid']") outstanding=$(field "d['data']['outstanding']") due=$(field "d['data']['due_date']")"
s=$(call POST "sales/$SB/payments" k-a4-pay-b2-0000001 '{"method":"QRIS","amount":"700000"}'); show "POST sales/{id}/payments QRIS 700.000" $s
echo "  payment=$(field "d['data']['number']") outstanding_after=$(field "d['data']['sale']['outstanding']")"
echo "  STOCK: $(stock)"

echo; echo "== Scenario C — retry the 700.000 payment with the SAME Idempotency-Key =="
s=$(call POST "sales/$SB/payments" k-a4-pay-b2-0000001 '{"method":"QRIS","amount":"700000"}'); show "RETRY POST sales/{id}/payments (same key)" $s
echo "  $(replayed); outstanding=$(field "d['data']['sale']['outstanding']"); payments rows for sale B: $(psqlq "select count(*) from payments where sale_id='$SB'")"
s=$(call POST "sales/$SB/payments" k-a4-pay-b3-0000001 '{"method":"CASH","amount":"400000"}'); show "overpayment 400.000 > outstanding 300.000 (new key)" $s

echo; echo "== Scenario D — sell more than stock (10 KG left, try 50 KG) =="
MOV_BEFORE=$(psqlq 'select count(*) from stock_movements'); SALES_BEFORE=$(psqlq 'select count(*) from sales')
s=$(call POST sales k-a4-sale-d-0000001 "{\"customer_id\":\"$ABC\",\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"50\",\"unit_price\":\"25000\"}]}"); show "POST sales 50 KG" $s
echo "  stock_movements before/after: $MOV_BEFORE/$(psqlq 'select count(*) from stock_movements')  sales before/after: $SALES_BEFORE/$(psqlq 'select count(*) from sales')"
echo "  STOCK: $(stock)"

echo; echo "== Scenario E — reverse a valid sale (walk-in 5 KG, paid cash) =="
s=$(call POST sales k-a4-sale-e-0000001 "{\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"5\",\"unit_price\":\"26000\"}],\"payments\":[{\"method\":\"CASH\",\"amount\":\"130000\"}]}"); show "POST sales (walk-in 5 KG)" $s
SE=$(field "d['data']['id']"); echo "  STOCK after sale: $(stock)"
s=$(call POST "sales/$SE/reverse" k-a4-rev-e-noflag01 '{"reason":"salah input"}'); show "reverse without reverse_payments (policy)" $s
s=$(call POST "sales/$SE/reverse" k-a4-rev-e-0000001 '{"reason":"salah input","reverse_payments":true}'); show "reverse with reverse_payments=true" $s
echo "  status=$(field "d['data']['status']") paid=$(field "d['data']['paid']")   STOCK after reversal: $(stock)"
s=$(call GET "inventory/movements?source_document_id=$SE" ""); show "GET inventory/movements (trace)" $s
j "'\n'.join('  #%s %-9s %+.4f  reverses=%s' % (m['sequence'], m['movement_type'], float(m['signed_quantity']), (m['reverses_movement_id'] or '-')[:8]) for m in reversed(d['data']))" < /tmp/smoke.body
s=$(call POST "sales/$SE/reverse" k-a4-rev-e-0000002 '{"reason":"lagi","reverse_payments":true}'); show "reverse again (must fail)" $s

echo; echo "== OWNER QUERY =="
s=$(call GET reports/daily ""); show "GET reports/daily" $s
j "'  sales: count=%s grand_total=%s | payments received=%s reversed=%s net=%s | by method: %s' % (d['data']['sales']['count'], d['data']['sales']['grand_total'], d['data']['payments']['received'], d['data']['payments']['reversed'], d['data']['payments']['net_received'], {k: v['received'] for k, v in d['data']['payments']['by_method'].items()})" < /tmp/smoke.body
s=$(call GET receivables/customers ""); show "GET receivables/customers" $s
j "'\n'.join('  %s %s owes %s (overdue %s, oldest due %s, available credit %s)' % (r['customer']['code'], r['customer']['name'], r['outstanding'], r['overdue'], r['oldest_due_date'], r['available_credit']) for r in d['data'])" < /tmp/smoke.body
s=$(call GET receivables/summary ""); show "GET receivables/summary" $s
s=$(call GET "sales?q=SAL" ""); show "GET sales?q=SAL (search, paged)" $s; echo "  total=$(field "d['meta']['total']")"
s=$(call GET inventory/integrity ""); show "GET inventory/integrity" $s
echo "  STOCK: $(stock)"

echo; echo "== Scenario F — SQL against payment history (bypassing the app) =="
set +e
echo "  UPDATE payments SET amount = 1:";       psqlq "UPDATE payments SET amount = 1" 2>&1 | sed 's/^/    /'
echo "  DELETE FROM payments:";                 psqlq "DELETE FROM payments" 2>&1 | sed 's/^/    /'
echo "  UPDATE sales SET paid_amount = 2500000 (sale B):"; psqlq "UPDATE sales SET paid_amount = 2500000 WHERE id = '$SB'" 2>&1 | sed 's/^/    /'
echo "  DELETE FROM payment_reversals:";        psqlq "DELETE FROM payment_reversals" 2>&1 | sed 's/^/    /'
set -e
echo "  payments rows=$(psqlq 'select count(*) from payments') sum=$(psqlq 'select sum(amount) from payments')  sale B paid=$(psqlq "select paid_amount from sales where id='$SB'") outstanding=$(psqlq "select outstanding from sales where id='$SB'")"

curl -s -o /dev/null -X POST "$BASE/auth/logout" -H "Authorization: Bearer $TOKEN"
echo; echo "logout ok"
