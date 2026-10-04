#!/usr/bin/env bash
# Phase A5-C live HTTP smoke test against a running server and a FRESH database.
# DIRECT FARM (collection date known) → lot → retail + B2B → receipts, lot label, QR/barcode, scan resolver
# AGENT (collection date unknown) → lot without collection date → label claims no collection/production date
#
# Preparation:
#   createdb dgway_smoke_c; export DB_DATABASE=dgway_smoke_c
#   php artisan migrate --force
#   printf '%s' "$PASSWORD" | php artisan dgway:bootstrap --owner-email="$EMAIL" --owner-name=Owner --password-stdin
#   php artisan serve --port=8765 &
# Usage: BASE=http://127.0.0.1:8765/api/v1 EMAIL=… PASSWORD=… PGPASSWORD=… DB_DATABASE=dgway_smoke_c OUT=docs/evidence/a5c-print-examples ./a5c-smoke-test.sh
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8765/api/v1}
DBNAME=${DB_DATABASE:-dgway_smoke_c}
OUT=${OUT:-/tmp/a5c-print-examples}
mkdir -p "$OUT"

j() { python3 -c "import sys,json; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }
call() { # method path key body
  local m=$1 p=$2 k=$3 b=${4:-}
  curl -s -D /tmp/smoke.headers -o /tmp/smoke.body -w '%{http_code}' -X "$m" "$BASE/$p" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    ${k:+-H "Idempotency-Key: $k"} ${b:+-d "$b"}
}
show() { printf '%-66s HTTP %s  %s\n' "$1" "$2" "$(head -c 110 /tmp/smoke.body)"; }
field() { j "$1" < /tmp/smoke.body; }
save() { python3 -m json.tool /tmp/smoke.body > "$OUT/$1.json"; }
stock() { call GET "inventory/current?product_id=$TLR&node_id=$NODE" "" >/dev/null; j "'available=' + d['data']['available']" < /tmp/smoke.body; }
psqlq() { psql -h 127.0.0.1 -U dgway -d "$DBNAME" -v ON_ERROR_STOP=1 -Atc "$1"; }
enc() { python3 -c "import sys,urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=''))" "$1"; }

echo "== LOGIN + DEFAULTS =="
TOKEN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device_name\":\"smoke-a5c\"}" | j "d['data']['token']")
echo "login ok (token redacted)"
s=$(call GET config ""); NODE=$(field "d['data']['default_node']['id']"); echo "  node=$(field "d['data']['default_node']['code']")"
s=$(call GET print/settings ""); show "GET print/settings (defaults)" $s; save print-settings-default
echo "  $(field "(d['data']['business_name'], d['data']['business_subtitle'], d['data']['receipt_width_mm'], d['data']['supported_receipt_widths_mm'], d['data']['is_default'])")"

echo; echo "== MASTER DATA =="
s=$(call POST business-partners k-a5c-sup-farm-001 '{"code":"sup-peternakan-bandung","name":"Peternakan Bandung","party_type":"ORGANIZATION","phone":"0812-1111-2222","supplier":{"term_days":0}}'); show "supplier Peternakan Bandung (direct farm)" $s; FARM=$(field "d['data']['id']")
s=$(call POST business-partners k-a5c-sup-agent-01 '{"code":"sup-agen-cimahi","name":"Agen Telur Cimahi","party_type":"ORGANIZATION","supplier":{"term_days":0}}'); show "supplier Agen Telur Cimahi (agent)" $s; AGENT=$(field "d['data']['id']")
s=$(call POST business-partners k-a5c-cus-abc-0001 '{"code":"cus-catering-abc","name":"Catering ABC","party_type":"ORGANIZATION","phone":"0812-3333-4444","customer":{"customer_type":"CATERING","term_days":7,"credit_limit":"5000000"}}'); show "customer Catering ABC" $s; ABC=$(field "d['data']['id']")
s=$(call POST categories k-a5c-cat-egg-00001 '{"code":"egg","name":"Telur"}'); CAT=$(field "d['data']['id']")
s=$(call POST products k-a5c-prod-tlr-0001 "{\"sku\":\"telur-std\",\"name\":\"Telur Ayam Standar\",\"category_id\":\"$CAT\",\"base_uom\":\"KG\"}"); show "product TELUR-STD" $s; TLR=$(field "d['data']['id']")
TODAY=$(field "d['data']['id']" >/dev/null; curl -s "$BASE/health" | j "d['data']['business_date']")
COLLECTED=$(python3 -c "import datetime,sys; print((datetime.date.fromisoformat(sys.argv[1]) - datetime.timedelta(days=1)).isoformat())" "$TODAY")
echo "  business date today=$TODAY  farm collection date=$COLLECTED"

echo; echo "== DIRECT FARM: PO 150 kg → GR (collection_date=$COLLECTED, 140 ACCEPT / 10 LOSS) =="
s=$(call POST purchase-orders k-a5c-po-create-001 "{\"supplier_id\":\"$FARM\",\"node_id\":\"$NODE\",\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"150\",\"unit_cost\":\"23000\"}]}"); show "POST purchase-orders" $s
PO=$(field "d['data']['id']"); POL=$(field "d['data']['lines'][0]['id']")
s=$(call POST "purchase-orders/$PO/confirm" k-a5c-po-confirm-01); show "confirm PO" $s
s=$(call GET "purchase-orders/$PO/print" ""); show "GET purchase-orders/{id}/print" $s; save purchase-order
s=$(call POST goods-receipts k-a5c-gr-farm-00001 "{\"supplier_id\":\"$FARM\",\"node_id\":\"$NODE\",\"purchase_order_id\":\"$PO\",\"lines\":[{\"product_id\":\"$TLR\",\"purchase_order_line_id\":\"$POL\",\"quantity\":\"150\",\"unit_cost\":\"23000\",\"collection_date\":\"$COLLECTED\",\"dispositions\":[{\"type\":\"ACCEPT\",\"quantity\":\"140\"},{\"type\":\"LOSS\",\"quantity\":\"10\",\"reason\":\"pecah\"}]}]}"); show "POST goods-receipts (direct farm)" $s
GR=$(field "d['data']['id']"); echo "  line collection_date=$(field "d['data']['lines'][0]['collection_date']") supplier_reference=$(field "d['data']['lines'][0]['supplier_reference']")"
s=$(call POST "goods-receipts/$GR/post" k-a5c-gr-farm-post1); show "post GR" $s
s=$(call GET "goods-receipts/$GR/print" ""); show "GET goods-receipts/{id}/print" $s; save goods-receipt
LOT=$(field "d['data']['document']['lines'][0]['lot']['id']"); LOTNO=$(field "d['data']['document']['lines'][0]['lot']['number']")
echo "  GR business_date=$(field "d['data']['document']['business_date']") line collection_date=$(field "d['data']['document']['lines'][0]['collection_date']") lot=$LOTNO"
echo "  DB lot: $(psqlq "select number||' collection_date='||coalesce(collection_date::text,'NULL')||' received(business_date)='||business_date||' supplier_reference='||coalesce(supplier_reference,'NULL') from inventory_lots where id='$LOT'")"
echo "  STOCK: $(stock)"

echo; echo "== RETAIL 30 kg × 27.000 cash; B2B 50 kg × 25.500 paid 775.000 (transfer 500.000 + QRIS 275.000) =="
s=$(call POST sales k-a5c-sale-retail-1 "{\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"30\",\"unit_price\":\"27000\"}],\"payments\":[{\"method\":\"CASH\",\"amount\":\"810000\"}]}"); show "retail sale" $s; SR=$(field "d['data']['id']")
s=$(call POST sales k-a5c-sale-b2b-0001 "{\"customer_id\":\"$ABC\",\"node_id\":\"$NODE\",\"confirm\":true,\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"50\",\"unit_price\":\"25500\"}],\"payments\":[{\"method\":\"BANK_TRANSFER\",\"amount\":\"500000\",\"reference\":\"TRF-BCA-A5C\"},{\"method\":\"QRIS\",\"amount\":\"275000\"}]}"); show "B2B sale" $s; SB=$(field "d['data']['id']")
echo "  STOCK: $(stock)   (expected 60)"
echo "  allocation: $(psqlq "select string_agg(s.number||' '||m.quantity||' from '||l.number, ' | ' order by m.sequence) from stock_movements m join sales s on s.id=m.source_document_id join inventory_lots l on l.id=m.lot_id where m.movement_type='SALE'")"

echo; echo "== RECEIPTS =="
s=$(call GET "sales/$SR/print" ""); show "GET sales/{retail}/print" $s; save receipt-retail
j "'  %s %s walk_in=%s customer=%s | grand_total=%s paid=%s outstanding=%s | payments=%s | width=%s' % (d['data']['document']['number'], d['data']['document']['status'], d['data']['document']['is_walk_in'], d['data']['document']['customer'], d['data']['document']['totals']['grand_total']['formatted'], d['data']['document']['totals']['total_paid']['formatted'], d['data']['document']['totals']['outstanding']['formatted'], [(p['method_label'], p['amount']['formatted']) for p in d['data']['document']['payments']], d['data']['layout']['paper_width_mm'])" < /tmp/smoke.body
echo "  header: $(field "(d['data']['business']['business_name'], d['data']['business']['business_subtitle'])")"
s=$(call GET "sales/$SB/print?width=58" ""); show "GET sales/{b2b}/print?width=58" $s; save receipt-b2b-58mm
j "'  %s %s customer=%s terms=%s | grand_total=%s paid=%s outstanding=%s | payments=%s | width=%s' % (d['data']['document']['number'], d['data']['document']['status'], d['data']['document']['customer'], d['data']['document']['payment_terms'], d['data']['document']['totals']['grand_total']['formatted'], d['data']['document']['totals']['total_paid']['formatted'], d['data']['document']['totals']['outstanding']['formatted'], [(p['method_label'], p['amount']['formatted']) for p in d['data']['document']['payments']], d['data']['layout']['paper_width_mm'])" < /tmp/smoke.body

echo; echo "== LOT LABEL + QR + BARCODE + SCAN =="
s=$(call GET "lots/$LOTNO/label" ""); show "GET lots/{number}/label" $s; save lot-label-direct-farm
QR=$(field "d['data']['document']['codes']['qr']['payload']"); BC=$(field "d['data']['document']['codes']['barcode']['value']")
echo "  QR=$QR  barcode=$(field "d['data']['document']['codes']['barcode']")"
j "'\n'.join('  %-24s %s' % (x['label'], x['value']) for x in d['data']['document']['display_lines'])" < /tmp/smoke.body
s=$(call GET "scan/resolve?code=$(enc "$QR")" ""); show "scan QR" $s; save scan-qr; echo "  → $(field "(d['data']['type'], d['data']['id'] == '$LOT', d['data']['matched_by'], d['data']['display']['available'])")"
s=$(call GET "scan/resolve?code=$(enc "$BC")" ""); show "scan barcode (lot number)" $s; echo "  → $(field "(d['data']['type'], d['data']['id'] == '$LOT', d['data']['matched_by'])")"
s=$(call GET "scan/resolve?code=TELUR-STD" ""); show "scan SKU" $s; echo "  → $(field "(d['data']['type'], d['data']['display']['sku'])")"
s=$(call GET "scan/resolve?code=LOT-000000-999" ""); show "scan unknown" $s
s=$(curl -s -o /tmp/smoke.body -w '%{http_code}' "$BASE/scan/resolve?code=$(enc "$QR")" -H 'Accept: application/json'); show "scan without token" $s
python3 - "$QR" "$BC" "$TOKEN" <<'PY'
import sys, re
qr, bc, token = sys.argv[1], sys.argv[2], sys.argv[3]
ok = re.fullmatch(r'dgway://lot/[0-9a-f-]{36}', qr) is not None and token[:10] not in qr and token[:10] not in bc
bad = [w for w in ['150', '27000', '25500', '500000', 'Bandung', 'ABC', '0812', 'token', 'key', '?', '&'] if w in qr]
print(f"  QR security check: canonical={ok} forbidden_fragments_found={bad}")
PY

echo; echo "== AGENT: GR without collection date =="
s=$(call POST goods-receipts k-a5c-gr-agent-0001 "{\"supplier_id\":\"$AGENT\",\"node_id\":\"$NODE\",\"lines\":[{\"product_id\":\"$TLR\",\"quantity\":\"40\",\"unit_cost\":\"22500\",\"dispositions\":[{\"type\":\"ACCEPT\",\"quantity\":\"40\"}]}]}"); show "POST goods-receipts (agent, no collection date)" $s; GA=$(field "d['data']['id']")
s=$(call POST "goods-receipts/$GA/post" k-a5c-gr-agent-post); show "post GR" $s
ALOT=$(psqlq "select number from inventory_lots where goods_receipt_id='$GA'")
echo "  DB lot: $(psqlq "select number||' collection_date='||coalesce(collection_date::text,'NULL')||' received='||business_date from inventory_lots where goods_receipt_id='$GA'")"
s=$(call GET "lots/$ALOT/label" ""); show "GET lots/{agent lot}/label" $s; save lot-label-agent
echo "  collection_date=$(field "d['data']['document']['collection_date']")  label keys=$(field "[x['key'] for x in d['data']['document']['display_lines']]")"
j "'\n'.join('  %-24s %s' % (x['label'], x['value']) for x in d['data']['document']['display_lines'])" < /tmp/smoke.body

echo; echo "== RECEIVABLE / DASHBOARD / ACTIVITY / TRACE / INTEGRITY =="
s=$(call GET receivables/customers ""); echo "  receivable: $(field "[(r['customer']['code'], r['outstanding']) for r in d['data']]")"
s=$(call GET dashboard ""); echo "  dashboard: sales=$(field "(d['data']['sales']['count'], d['data']['sales']['total'])") payments=$(field "d['data']['payments']['total']") receivable=$(field "d['data']['receivables']['total']") stock=$(field "d['data']['inventory']['stock_by_uom']")"
s=$(call GET "activity?per_page=50" ""); echo "  activity types: $(field "sorted(set(a['type'] for a in d['data']))")  receivable_change=$(field "d['summary']['receivable_change']")"
s=$(call GET inventory/integrity ""); echo "  integrity: $(field "d['data']['consistent']")"
echo "  php artisan dgway:trace $LOTNO (head):"
php artisan dgway:trace "$LOTNO" | head -4 | sed 's/^/    /'
echo "  print/scan audit rows (expected 0 — read-only): $(psqlq "select count(*) from audit_logs where action like 'print%' or action like 'scan%'")"
curl -s -o /dev/null -X POST "$BASE/auth/logout" -H "Authorization: Bearer $TOKEN"
echo; echo "logout ok"
