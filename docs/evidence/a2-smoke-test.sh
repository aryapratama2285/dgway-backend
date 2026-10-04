#!/usr/bin/env bash
# Phase A2 HTTP smoke test against a running server (php artisan serve).
# Usage: BASE=http://127.0.0.1:8765/api/v1 EMAIL=… PASSWORD=… ./a2-smoke-test.sh
set -euo pipefail
BASE=${BASE:-http://127.0.0.1:8765/api/v1}

j() { python3 -c "import sys,json; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }
call() { # method path key body
  local m=$1 p=$2 k=$3 b=${4:-}
  curl -s -o /tmp/smoke.body -w '%{http_code}' -X "$m" "$BASE/$p" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    ${k:+-H "Idempotency-Key: $k"} ${b:+-d "$b"}
}
show() { printf '%-58s HTTP %s  %s\n' "$1" "$2" "$(head -c 170 /tmp/smoke.body)"; }

TOKEN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device_name\":\"smoke\"}" | j "d['data']['token']")
echo "login ok (token redacted)"

s=$(call POST categories k-cat-food-0000000001 '{"code":"food","name":"Food"}'); show "POST categories FOOD" $s
FOOD=$(j "d['data']['id']" < /tmp/smoke.body)
s=$(call POST categories k-cat-egg-00000000001 "{\"code\":\"egg\",\"name\":\"Telur\",\"parent_id\":\"$FOOD\"}"); show "POST categories EGG under FOOD" $s
EGG=$(j "d['data']['id']" < /tmp/smoke.body)

BODY="{\"sku\":\"tlr-a\",\"name\":\"Telur Ayam Grade A\",\"category_id\":\"$EGG\",\"base_uom\":\"KG\"}"
s=$(call POST products k-prod-tlr-a-000000001 "$BODY"); show "POST products TLR-A" $s
P1=$(j "d['data']['id']" < /tmp/smoke.body)
s=$(call POST products k-prod-tlr-a-000000001 "$BODY"); show "POST products TLR-A (RETRY same key)" $s
P2=$(j "d['data']['id']" < /tmp/smoke.body)
[ "$P1" = "$P2" ] && echo "  -> retry returned the SAME product id: $P1"
s=$(call POST products k-prod-tlr-a-000000002 "$BODY"); show "POST products TLR-A (new key = duplicate)" $s
s=$(call POST products "" "$BODY"); show "POST products without Idempotency-Key" $s
s=$(call PATCH "products/$P1" k-prod-upd-0000000001 '{"name":"Telur Ayam Negeri A","expected_version":1}'); show "PATCH product rename (v1)" $s
s=$(call PATCH "products/$P1" k-prod-upd-0000000002 '{"name":"Stale edit","expected_version":1}'); show "PATCH product with stale version" $s
s=$(call POST "categories/$EGG/deactivate" k-cat-deact-000000001); show "deactivate category with active product" $s
s=$(call POST "products/$P1/deactivate" k-prod-deact-00000001); show "deactivate product" $s
s=$(call GET "products/$P1" ""); show "GET inactive product (history kept)" $s

s=$(call POST business-partners k-bp-monalisa-0000001 '{"code":"cus-monalisa","name":"Bakso Monalisa","party_type":"ORGANIZATION","phone":"0812-1111-2222","customer":{"customer_type":"RESTAURANT"}}'); show "POST business-partners (cash customer)" $s
BP=$(j "d['data']['id']" < /tmp/smoke.body)
s=$(call PATCH "business-partners/$BP/roles/customer" k-bp-terms-000000001 '{"term_days":7,"credit_limit":"1500000"}'); show "PATCH customer credit terms" $s
s=$(call POST "business-partners/$BP/roles" k-bp-role-0000000001 '{"role":"SUPPLIER","term_days":0}'); show "assign SUPPLIER role to same partner" $s
s=$(call POST business-partners k-bp-walkin-00000001 '{"code":"walkin","name":"Umum","party_type":"INDIVIDUAL","customer":{"customer_type":"WALK_IN","term_days":7}}'); show "walk-in with tempo (must fail)" $s
s=$(call GET "business-partners?q=%2B6281211112222" ""); show "search partner by phone (+62 form)" $s

s=$(call POST nodes k-node-van-000000001 '{"code":"van-01","name":"Mobil Box","capabilities":["DELIVERY"]}'); show "POST nodes VAN-01 (no location)" $s
s=$(call GET "nodes?capability=INVENTORY" ""); show "GET nodes with INVENTORY capability" $s
LOC=$(curl -s "$BASE/locations" -H "Authorization: Bearer $TOKEN" | j "d['data'][0]['id']")
s=$(call POST "locations/$LOC/deactivate" k-loc-deact-00000001); show "deactivate location hosting active node" $s

curl -s -o /dev/null -X POST "$BASE/auth/logout" -H "Authorization: Bearer $TOKEN"
echo "logout ok"
