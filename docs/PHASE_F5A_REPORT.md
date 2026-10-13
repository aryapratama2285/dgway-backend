# Phase F5A — Operational Hardening

F5A makes the verified F1–F4 system safe for several operators on several phones over a real
mobile network, and removes the last reasons to touch the API by hand. It is an evolution of F4:
no business domain was added, no ledger/sales/procurement model changed, no shipped migration was
edited. F5B (visual redesign), F5C (printing) and F5D (deployment) are NOT part of this phase.

## 1. Baseline

| Repository | Baseline | F5A work |
| --- | --- | --- |
| dgway-backend | `phase-f4b` `a11f029` | branch `phase-f5a` (uncommitted), delivered as patch + overlay |
| dgway-owner-app | `phase-f4b` `c4211a9` | branch `phase-f5a` (uncommitted), delivered as patch + overlay |

## 2. Locked decisions applied

| # | Decision | Where |
| --- | --- | --- |
| D1 | STAFF role for routine work; no sensitive capability | migration `f5a_staff_role`, `Role::STAFF`, `config/dgway.php` (`STAFF => []`), three new permissions, `permissions` in the auth payload, app gating by permission |
| D2 | Units TRAY, BOX (Dus/Box), PACK, ROLL, LITER next to KG, PCS, IKAT; sizes per product only | migration `f5a_purchasing_units` (reference rows, no size); conversion screen on the product |
| D3 | Logo later | nothing changed (launcher icon untouched) |
| D4 | Share-as-PDF later, no ESC/POS | nothing printed in F5A |
| D5 | One VPS later | only the code-side prerequisites (proxy trust, logs, scheduler, doctor) |
| D6 | `id.dgway.owner` | Android `applicationId`, `namespace`, `MainActivity` package; release signing via `key.properties` |

## 3. Backend delta

**STAFF and permissions (D1).** `users_role_check` now accepts `OWNER` and `STAFF`
(down() refuses while a STAFF account exists). STAFF holds no entry in `role_permissions`.
Three permissions were added through the existing `Authorizer` (no redesign):

| Permission | Guards | Notes |
| --- | --- | --- |
| `inventory.correct` | loss, regrade, recovery (quarantine release), supplier return | internal consumption stays routine; ADJUSTMENT keeps `inventory.adjust` |
| `partners.manage_credit` | creating a customer / assigning the customer role with term > 0 or a credit limit; ANY change of customer terms | a cash customer (term 0, no limit) is routine; re-sending identical terms is not a change |
| `network.manage` | every node / location command | the system actor (console, `dgway:bootstrap`) passes via the new `Authorizer::authorizeIfOperator()` |

The existing sensitive permissions (reversals, returns, `inventory.adjust`, credit-limit override,
PO close/cancel, GR reversal, UOM conversions, print settings) are simply not granted to STAFF.
`OperatorResource` gains `permissions` (additive) on login and `GET /auth/me`; `Authorizer::permissionsOf()`
expands `*`. Operator administration remains console-only: `dgway:operator:create --role=STAFF`,
`dgway:operator:list`, `dgway:operator:deactivate [--activate]` (revokes every device token),
`dgway:operator:reset-password` (revokes every device token) — all audited.

**Password change.** `POST /auth/password {current_password, new_password}`: throttled 5/min per operator,
422 `CURRENT_PASSWORD_INVALID` (never 401, so the app does not log out), `PASSWORD_TOO_SHORT`,
`PASSWORD_UNCHANGED`; revokes every other token of the operator, keeps the calling device; audited without
password material. Deliberately outside the idempotency middleware (the store keeps a hash of command bodies).

**Units (D2).** Five reference rows with fixed ids, `ON CONFLICT DO NOTHING`. down() leaves them in place:
`uoms` rows are guarded against DELETE and a rollback + re-migrate must leave the table byte-identical
(`LotReconciliationTest` fingerprints it). Sizes stay in `product_uom_conversions` — the test proves one TRAY
= 1.8 KG for eggs and 30 PCS for another product.

**Production edge (P0-5/6, P1-8/9).**
- `DGWAY_TRUSTED_PROXIES` → `TrustProxies::at()` (empty = trust none). Live-checked: with the proxy trusted,
  `audit_logs.context.ip` and the login limiter use the `X-Forwarded-For` client address.
- `LOG_STACK` default `daily` (14 days); `stderr` documented for containers.
- Scheduler: `sanctum:prune-expired --hours=24` (03:25 WIB), `dgway:inventory:verify` (03:35 WIB, CRITICAL log on
  mismatch), a one-minute heartbeat file.
- `dgway:doctor --production`: FAIL on non-https `APP_URL` and on a `single` log channel; WARN on no trusted
  proxy and on a heartbeat older than 5 minutes; "bootstrap: operator" now requires an active OWNER.

**Documentation.** `API_CONTRACT_V1.md` (roles/permissions, `POST /auth/password`, endpoint catalog),
`API_ERROR_CODES.md` (F5A section), `DEPLOYMENT.md` (env table, scheduler jobs, operator runbook, security
checklist), `README.md`, regenerated `SCHEMA_CATALOG.md`, `.env.example`.

**Changed existing tests (documented behaviour changes, nothing weakened):**
- `CountedAdjustmentTest`: a loss without permission is now 403 `inventory.correct` (was allowed) — decision D1.
- `DatabaseSchemaTest`, `CatalogApiTest`: the unit list now has eight units — decision D2.
- `DoctorCommandTest::test_safe_production_settings_pass`: the "safe" configuration now also sets an https
  `APP_URL` and a `daily` log channel (new production rules), independent of the local `.env`.

## 4. Flutter delta

**P0 — pending commands.** `CommandRunner` now persists every command (key, method, path, frozen body,
operator, time) in Keystore-backed storage *before* sending and forgets it once the outcome is known
(applied, refused, or definitely not sent). Uncertain ones survive Back, process death and restarts; the
shell shows "N transaksi belum pasti hasilnya" and *Lainnya → Transaksi tertunda* resends them with the SAME
key and body (`IdempotentCommand.restore`). While one is pending, a NEW intent by the same operator on the same
endpoint is refused on the phone (`PENDING_COMMAND_UNRESOLVED`, nothing sent). Every command screen has a
Back guard (stay, or leave with the transaction kept pending). Entries older than 25 days are not resent.

**Retry = the same command.** The F4B/F4A lost-answer heuristics (re-read and guess, e.g. "exactly one new
payment with this method/amount/reference") were removed from payment, return, sale confirm/cancel/reverse,
payment reversal, PO confirm/cancel/close, GR post/cancel and receive. "Coba lagi" always resends the same
key and body; the server decides.

**Master data from the phone.** Create / edit / (de)activate products, categories, customers and suppliers
(existing endpoints; edits send only changed fields + `expected_version`; nothing deleted). Customer terms
have their own screen; tempo/limit fields appear only with `partners.manage_credit` (STAFF creates cash
customers). The walk-in customer cannot be edited. Product detail lists purchasing units; OWNER adds/edits
"1 TRAY = 1,8 KG" per product.

**Corrections.** GR detail: "Batalkan penerimaan (reversal)" for POSTED receipts (`procurement.reverse`).
Stock detail: "Lepas karantina" for QUARANTINE / RETURN_PENDING stock (`inventory.correct`).

**Permissions.** `operator.permissions` drives every sensitive action (sale return/reversal, payment reversal,
credit override, stock adjustment, quarantine release, PO close/cancel, GR reversal, conversions, credit terms).
Without the list (older backend) OWNER = everything, other roles = nothing.

**Account, password, freshness.** Account shows role (Pemilik/Staf), access summary, pending count, API host,
full URL, version (`pubspec.yaml`, test-enforced), build id (`APP_BUILD`) and environment; "Ganti password".
The shell re-reads sales, receivables, stock and the dashboard on app resume and a tab's list on re-entry
after 30 s; the dashboard shows "Diperbarui hh:mm".

**Release identity (D6).** `id.dgway.owner`; release signing from `android/key.properties` (debug-key
fallback for development only); `config/dart_defines.production.example.json`; per-machine defines ignored.

**Cleanup.** Removed `lib/features/placeholders/placeholder_page.dart` (unreferenced) and `F1_APPLY.md`;
README rewritten; `pubspec.yaml` 0.5.0+5.

**Changed existing tests:** four "re-read → nothing re-sent" tests became "applied on the server → the SAME
key is resent and the server replays → applied once" (F4B confirm, F4B payment, F4A confirm, F4A receive post);
two expectations that a re-read happens before a resend were dropped; `testOverrides`/`pumpShell` accept an
in-memory pending store.

## 5. Gates

### Backend — executed here (PHP 8.3.6, PostgreSQL 16.x, 2026-10-11)

| Gate | Result |
| --- | --- |
| `vendor/bin/phpunit` (full) | **OK — 618 tests, 21,917 assertions** (F4B baseline 604 / 21,692; +14 tests in `F5AHardeningTest`) |
| Concurrency / idempotency / contract / architecture subset (`--filter Concurrency\|MultiProcess\|Idempotency\|F5AHardening\|MobileApiContract\|Architecture\|TransactionRetry`) | OK — 89 tests, 1,250 assertions |
| Same Idempotency-Key from two operators | independent results, each replays its own (`test_same_idempotency_key_from_two_operators_is_scoped_per_operator`) |
| Error codes / endpoint catalog documented | OK (`MobileApiContractTest`) |
| `migrate:fresh` → `migrate:rollback --step=200` (all) → `migrate`; then `rollback --step=2` + `migrate` (both F5A migrations) | OK on a scratch database |
| STAFF rollback guard | `Refusing to roll back F5A: STAFF operator accounts exist.` once a STAFF account exists |
| `dgway:schema:catalog --check` | up to date |
| `dgway:inventory:verify` | consistent |
| `dgway:doctor --production` (production-like env: APP_ENV=production, APP_DEBUG=false, https APP_URL, trusted proxy, daily logs, scheduler heartbeat, bootstrapped owner) | **Configuration OK.** (22 checks OK) |
| Live HTTP smoke (`artisan serve`) | STAFF login → `permissions: []`; STAFF `POST /nodes` → 403 `network.manage`; STAFF cash customer → 201; STAFF credit customer → 403 `partners.manage_credit`; `POST /auth/password` → 200; with `DGWAY_TRUSTED_PROXIES=127.0.0.1` the audit `context.ip` = `X-Forwarded-For` client |
| `php -l` on changed files; `git diff --check` | clean |

### Flutter — NOT executed here

This environment cannot download the Flutter/Dart SDK or reach pub.dev (blocked by network policy).
The Dart code was checked only statically (bracket balance, imports and APIs reviewed by hand against the
existing code). **`flutter analyze`, `flutter test` and the APK build must run in Codespaces before F5A is
accepted.** Expected test count: **218** (186 existing + 32 new; 4 existing tests rewritten in place).

## 6. Known limitations

- Flutter gates unexecuted (above); expect a small number of analyzer/test fixes in Codespaces.
- Pending commands are per phone and per operator; logging out keeps them for the same operator only.
- A pending command older than 25 days is not resent automatically (server keeps keys 30 days).
- Supplier payment terms and partner contact data of the walk-in customer are not editable in the app;
  nodes/locations remain read-only in the app (OWNER via API/console, `network.manage`).
- Password change after a lost answer cannot be replayed (by design); the screen explains it.
- Units cannot be created from the app (reference data by migration, D2).
- Freshness is pull-based (resume / tab re-entry / pull-to-refresh), no push.
- Release signing needs the owner to create and safeguard the keystore (`docs/FLUTTER_ANDROID_SETUP.md §6`).

## 7. Codespaces verification — 2026-10-14

This update supersedes the earlier statement that Flutter gates were not executed.

- Backend: PHP 8.5.1, PostgreSQL 16.15; PHPUnit passed:
  618 tests, 21,920 assertions, using the separate dgway_test database.
- Flutter: analyze passed with no issues; all 220 tests passed; debug APK built.
- Owner app fixes pushed to phase-f5a in commit c9b24a8.
- Android manual checks passed: automatic two-character search, keyboard layout,
  supplier/product activation and status searches, and BOX conversion activation.
- Normal commands no longer show the uncertain-outcome banner.
- Controlled lost-response test: product deactivation persisted, replay was
  recognized as already recorded, and the pending entry and banner cleared.
- Product restored to active. Temporary response-delay code and marker removed.
- Database backup downloaded to the laptop.
- Repeated Docker RWLayer failures remain an unresolved environment issue.
- These checks do not constitute production deployment or full release acceptance.
