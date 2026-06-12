# WHMCS contract verification

**Status: 5 of the original 9 items VERIFIED via developers.whmcs.com on
2026-05-13.** §10 (multi-server re-home) and §11 (client-area enrichment
+ self-service) were added later. The remaining items (§3, §7, §8, §9,
§10, §11) need confirmation against the target WHMCS install during the
pre-deploy smoke (Phase 14.2).

## 1. `$params` shape per hook — VERIFIED ✓

Citation: [WHMCS Module Parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/).

- `serverhostname`, `serversecure`, `serverpassword` set on every hook.
  **Transport policy:** `ServerConfig::fromParams` now refuses to build a
  plaintext `http://` base URL for a public host — if `serversecure` is
  off and the hostname is not loopback/private-range
  (localhost/`127.`/`10.`/`172.16–31.`/`192.168.`/`169.254.`/IPv6
  `::1`/`fc00::/7`), it throws `InvalidArgumentException` rather than send
  the admin key/passwords in the clear. Hooks surface this as a config
  error (e.g. Test Connection); plaintext is allowed only for LAN/dev
  backends. TLS peer+host verification is set explicitly on both the cURL
  and stream transports.
- `serviceid` present on lifecycle and admin button handlers.
- `clientsdetails` is an array of client fields (firstname, lastname, email, …).
- `password` provided on CreateAccount and ChangePassword (cleartext).
- `customfields` keyed by field NAME (confirmed).
- `configoption1..configoption24` for per-product config values.
- `configoptions` keyed by configurable-option NAME → selected VALUE.
- **No `status` / `domainstatus` key.** Re-verified against the same
  citation on 2026-05-17: the documented parameter table contains no
  service-status field. A provisioning hook cannot learn whether the
  service is Active/Suspended/Terminated from `$params`.

  **Consequence:** any handler that must assert the Silo user's
  `enabled` state (Silo's `updateUser` is a partial PATCH — see
  `auth/repository.go` `Update`, which only writes `enabled` when it is
  present, so an omitted value preserves a stale disabled state) must read
  `tblhosting.domainstatus` directly via `Capsule`, the same source
  `DailyReconciler` uses. `ChangePackage` (also the target of the
  "Reconcile from WHMCS" button) now does this with a fail-safe default:
  it only sends `enabled => false` on a *definite* non-active status; a
  missing/empty row keeps the user enabled so a reconcile can never
  silently lock out a working customer. `CreateAccount` instead hardcodes
  `enabled => true` — it is only ever invoked when the account should
  exist and be usable; suspension is `SuspendAccount`'s responsibility.

## 2. `UpdateClientProduct` customfields payload — VERIFIED ✓ + FIXED ✓

Citation: [WHMCS UpdateClientProduct](https://developers.whmcs.com/api-reference/updateclientproduct/) + [community thread on field-id vs field-name](https://whmcs.community/topic/156361-api-updateclientproduct-customfields/).

The `customfields` parameter is:

```
base64_encode(serialize([<numeric_tblcustomfields.id> => <value>]))
```

**The key must be the numeric field ID, NOT the field name.** Using the
field name as key causes the API to return `success` while silently
dropping the write.

**Fix:** `Whmcs\CustomFieldStore` (commit `2679c9d`) resolves field name →
numeric id via `GetClientsProducts` (which returns `{id, name, value}` per
field), memoises per service, then writes with the numeric key. All
existing writes route through `CustomFieldStore::write` since commit
`adade2a`.

## 3. `GetClientsProducts` response shape — PARTIALLY VERIFIED

The documented shape is `$resp['products']['product'][i]['customfields']['customfield'][]`
as an array of dicts. The dicts include `id`, `name`, `value` keys (used
by `CustomFieldStore::resolveId`). The official docs do not enumerate
every key in the customfield dict, so the `id` presence is a working
assumption confirmed by community use; verify against your WHMCS version.

**Risk:** if `customfield` is a single dict in single-entry mode (instead
of always an array), `CustomFieldStore::loadFields` returns the wrong
shape and resolution fails. Pre-deploy smoke should land at least one
service through CreateAccount and verify `silo_user_id` is populated
correctly.

## 4. Custom-button handler return type — VERIFIED ✓ + FIXED ✓

Citation: [WHMCS Custom Functions](https://developers.whmcs.com/provisioning-modules/custom-functions/) + [WHMCS Custom Actions](https://developers.whmcs.com/provisioning-modules/custom-actions/).

Button handlers registered via `AdminCustomButtonArray` MUST return a
status string (`'success'` or an error message). The previous return
shape `['redirect' => …, 'newWindow' => true]` is undocumented and not
recognised by WHMCS — the button silently does nothing.

The newer **Custom Actions** feature (WHMCS 8.5+) supports
`['success' => true, 'redirectTo' => 'url']` but has no `newWindow` flag.

**Fix:** The "Open in Silo" deep-link no longer goes through a custom
button. It renders as a regular `<a target="_blank" rel="noopener">`
inside the `AdminServicesTabFields` output, where arbitrary HTML is
supported (commit `7c3fdbb`).

## 5. `ClientArea` return shape — VERIFIED ✓

Citation: [WHMCS Client Area Output](https://developers.whmcs.com/provisioning-modules/client-area-output/).

```
['templatefile' => 'clientarea', 'vars' => [ ... ]]
```

Resolves to `modules/servers/<modulename>/templates/clientarea.tpl`. The
module's existing return shape matches. The enriched template only adds
`vars` keys (username, plan, profiles, watching-now, member-since); the
contract shape is unchanged. The client-area self-service button is
registered via `ClientAreaCustomButtonArray` and its handler
(`silo_clientarea_resetpw`) returns a status string — the same
contract as the admin custom buttons in §4 (verify the button renders
and its returned message displays on the target theme/version).

## 6. `ClientEdit` hook payload — VERIFIED ✓

Citation: [WHMCS hooks reference (client)](https://developers.whmcs.com/hooks-reference/client/).

`$vars` includes `userid`, `email`, `firstname`, `lastname`, and an
`olddata` sub-array of the previous values. The handler in `hooks.php`
compares `email` vs `olddata.email` to detect renames.

## 7. Server-level `reconcile_daily` flag — REMOVED

Previously the module exposed a per-server `reconcile_daily` opt-in, but
WHMCS's server form provides no UI for extra named fields and the flag
was always-on in practice. The plumbing has been removed: `DailyCronJob`
in `hooks.php` now reconciles **every** Silo-typed server every
day, unconditionally. If a per-server opt-out becomes necessary, the
cleanest re-introduction is a per-product config option (the only place
WHMCS reliably surfaces extra fields). Slot map remains: there is no
`role` option (every user is provisioned as `user`); `configoption10` /
`configoption11` / `configoption12` / `configoption13` are
`delete_on_terminate` / `auto_rehome_on_reorder` /
`allow_client_reset_password` / `client_reset_cooldown`; the next free
slot is `configoption14`.

## 8. `UpdateClientProduct` service-credential params + `serverport` — FIXED ✓ (verify pre-deploy)

Citation: [WHMCS UpdateClientProduct](https://developers.whmcs.com/api-reference/updateclientproduct/) + [WHMCS Module Parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/).

Same gotcha family as §2 — `UpdateClientProduct` ignores unrecognised
parameters and still returns `success`.

- The service login is written via `serviceusername` / `servicepassword`,
  **not** `username` / `password` / `serverpassword`. Previously
  `CreateAccount` wrote `username` and `AdminResetPassword` wrote
  `serverpassword`; both were silently dropped, so the WHMCS service
  record never reflected the generated username or the reset password.
  **Fix:** `CreateAccount::writeServiceUsername` now sends
  `serviceusername`; `AdminResetPassword` now sends `servicepassword`.

- `serverport` is read from `$params` (and from `tblservers.port` in the
  cron/`ClientEdit` paths) and folded into the API base URL by
  `ServerConfig::fromParams`. `MetaData` now declares
  `DefaultNonSSLPort` / `DefaultSSLPort` so the server form populates a
  sensible default instead of prompting for an empty port.

**Risk / to verify:** confirm on the target WHMCS version that (a) the
written `serviceusername` / `servicepassword` actually land on
`tblhosting`, and (b) a non-default port set on the server form surfaces
as `$params['serverport']`.

## 9. Direct schema writes for auto-provisioning — FIXED ✓ (verify pre-deploy)

WHMCS has no public API to create custom fields or configurable options,
so the module writes the schema tables directly via `Capsule` — exactly
what the WHMCS admin UI does. Assumptions to confirm on the target
version:

- `tblcustomfields` columns/semantics: `type='product'`,
  `relid=<tblproducts.id>`, `fieldname`, `fieldtype='text'`,
  `adminonly`/`showorder`/`required` are `'on'`/`''`, `regexpr` holds a
  PHP-style regex, `created_at`/`updated_at` are non-null timestamps.
  Used by `Whmcs\CustomFieldProvisioner`.
- Configurable options span `tblproductconfiggroups` →
  `tblproductconfigoptions` (`optiontype` 1=dropdown, 2=radio,
  3=yes/no, 4=quantity; `order` column) → `tblproductconfigoptionssub`
  → `tblpricing` (`type='configoptions'`, `relid=<sub.id>`, one row per
  `tblcurrencies` id) → `tblproductconfiglinks` (`gid`,`pid`). Used by
  `Whmcs\ConfigOptionScaffolder`.
- **Custom-field `name | Label` pipe — VERIFIED on this install.**
  WHMCS treats the text *after* the `|` as the field's canonical
  name/param key and does **not** trim it: `emby_connect_email | Please
  enter ...` is returned by `GetClientsProducts` as
  `name = " Please enter ..."` (leading space preserved). So a piped
  `desired_username|Enter your desired username` is keyed in
  `$params['customfields']` as `Enter your desired username`, NOT
  `desired_username`. The module therefore resolves it tolerantly
  (`Params::desiredUsername`: exact key, else pre-pipe == desired_username,
  else a key containing "desired" + "username"), and
  `CustomFieldProvisioner` dedupes by the pre-pipe base name so a
  pre-existing plain `desired_username` is never duplicated. Use **no
  spaces** around the `|` so the key/label has no stray leading space.

All writes are idempotent (match by name/base/keys) and never overwrite
admin-set pricing or admin-created/edited fields (create-if-missing only;
visibility changes an admin makes are never reverted).

**Risk / to verify:** confirm on the target WHMCS version that scaffolded
options render on the order form, that the post-pipe custom-field naming
behaviour above still holds, and that `Params::desiredUsername` resolves
a customer-entered value when an admin has enabled Show on Order Form.

## 10. Multi-server re-home (`auto_rehome_on_reorder`) — FIXED ✓ (verify pre-deploy)

Citation: [WHMCS UpdateClientProduct](https://developers.whmcs.com/api-reference/updateclientproduct/) + [WHMCS Module Parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/).

Opt-in (`configoption11`, default OFF). When ON, `CreateAccount` may
move a service to the Silo server that already hosts the returning
customer, then re-link the existing user instead of creating a fresh
account. Contract assumptions on the target WHMCS version:

- **Service re-pointing.** `UpdateClientProduct` accepts `serverid` to
  move a service between servers — but, like the §2/§8 gotcha family, an
  unrecognised/ignored parameter still returns `success`. The module
  therefore does **not** trust it: after the API call it reads back
  `tblhosting.server` and, if unchanged, forces a direct
  `Capsule::table('tblhosting')->update(['server' => …])`, then
  re-verifies. Only an unverified move (both paths failed) is fatal —
  re-home never silently creates a fresh account. **Verify:** a
  re-homed service genuinely runs on the new server, i.e. subsequent
  hooks (Suspend/ChangePackage/ClientArea) receive the new server's
  connection params in `$params`.
- **Cross-server scan.** `ServerRegistry` reads `tblservers` filtered to
  `type='silo'`, skips rows with `disabled = 1`, and builds a
  per-server client from `hostname`/`port`/`secure`/`decrypt(password)`
  — the same shape `hooks.php` (DailyCronJob/ClientEdit) already relies
  on. **Verify:** `tblservers.disabled` truthiness (1 = disabled) and
  that `decrypt()` is callable from the `CreateAccount` path on the
  target version (it is in cron/`ClientEdit`; CreateAccount is a new
  caller).
- **Pointer table.** `HomeStore` creates `mod_silo_home` on demand
  via `Capsule::schema()` (same direct-schema philosophy as §9). It is a
  pure cache: every method is best-effort and degrades to a full scan,
  so a missing schema builder or insufficient `CREATE` grant only costs
  a re-scan, never correctness. **Verify:** the table is created (or the
  DB user *can* create it) on the target; otherwise note that re-home
  runs scan-only.

**Risk / to verify (end-to-end):** with `auto_rehome_on_reorder` ON and
`delete_on_terminate` OFF — terminate a service on server A, place a
**new** order that WHMCS routes to server B, and confirm: the service
ends up on **A**, the existing Silo user is re-enabled with history
intact, no fresh account was created on B, the `mod_silo_home`
pointer row exists, and a follow-up hook (e.g. Suspend) operates on A.

## 11. Client-area enrichment + self-service — FIXED ✓ (verify pre-deploy)

Citation: Silo admin API (`/opt/silo` router) + [WHMCS Client Area Output](https://developers.whmcs.com/provisioning-modules/client-area-output/).

The client area now reads two extra admin endpoints and exposes a
self-service action. Assumptions to confirm on the target Silo
version:

- **Endpoints.** `GET /api/v1/admin/users/{id}/profiles` → `[{id,name}]`;
  `GET /api/v1/admin/sessions` → server-wide playback rows including
  `user_id` and `client_ip`. The handler filters to the linked user and
  surfaces only titles/counts — **`client_ip`/bitrates are never sent to
  the customer**. The same `client_ip`/`ip_address` values are also
  redacted from Silo response bodies before they are written to the WHMCS
  Module Log (`Client::redactResponseBodyForLog`), so the sessions PII
  never lands in diagnostics either. Each call degrades independently (a
  failure blanks only that row, never the page). **Verify:** both
  endpoints exist and return those shapes; the page renders with one or
  both unavailable.
- **Password reset = sign-out-everywhere.** The self-service button is
  gated by `configoption12` (`allow_client_reset_password`, default ON).
  When enabled, it changes the password via admin `updateUser`, which
  Silo treats as session-revoking (`updateRequiresSessionRevocation` is
  true when `password` is set). The generated password is shown once in
  the returned WHMCS action message and written to the WHMCS service
  password; turn the option OFF when resets should be staff-only.
  The action is also rate-limited per service: `PasswordResetThrottle`
  (table `mod_silo_pw_reset`) enforces a cooldown set by `configoption13`
  (`client_reset_cooldown`, default 60s, 0 disables), so the
  sign-out-everywhere button can't be spammed into a lockout. The
  cooldown only records after a *successful* reset, so a failed Silo
  call doesn't start the timer. **Verify:** after a client-area reset, a
  previously-authenticated Silo session is actually rejected — this is
  the claim shown to the customer — and an immediate second reset is
  refused with a "please wait" message.
- **Cost.** Up to ~3 admin-API calls per client-area view (`getUser` +
  profiles + sessions; library names stay 24h-cached). `/admin/sessions`
  is server-wide and filtered client-side — fine at normal volume; note
  if a server-side `?user_id=` filter becomes necessary at scale.

---

## Items still to verify pre-deploy

- §3: the `customfield` dict shape on the target WHMCS version.
- §7: (resolved — flag removed; daily cron runs unconditionally.)
- §8: `serviceusername` / `servicepassword` write-back persists, and a
  non-default server-form port surfaces as `$params['serverport']`.
- §9: scaffolded configurable options + auto-created custom fields render
  correctly on the order form, and `configoption9` is still "Allow
  customer-chosen username" while `configoption12` / `configoption13` are
  "Allow client-area password reset" / "Client reset cooldown (seconds)"
  (no `role` option; numbering starts at `library_ids` =
  `configoption1`).
- §1: the `enabled`-state assertion. (a) Terminate a service with
  `delete_on_terminate=OFF`, re-order on the **same** server, and confirm
  the Silo user comes back **enabled** — not merely relinked. (b) Run
  "Reconcile from WHMCS" against a **Suspended** service and confirm the
  user stays **disabled** (the fail-safe default must not resurrect a
  non-payer). (c) Confirm `Capsule` can read `tblhosting.domainstatus`
  from within the `ChangePackage` hook on the target WHMCS version.
- §10: multi-server re-home end-to-end (terminate on A → new order routed
  to B → service ends on A, user re-enabled, history intact, pointer row
  written, follow-up hook operates on A); `tblservers.disabled`
  semantics; `decrypt()` callable from `CreateAccount`; `Capsule::schema()`
  can create `mod_silo_home` (or the DB user can).
- §11: client area renders with the profiles/sessions endpoints present
  AND with each unavailable (independent degradation); `client_ip` never
  appears; the self-service "reset password & sign out" button renders,
  returns its message on the theme, and a live Silo session is
  actually rejected afterward.

Use [staging-smoke-checklist.md](staging-smoke-checklist.md) for the
step-by-step staging run. It covers one CreateAccount, one Reset Password,
one scaffold, one daily-cron, one terminate→same-server re-order, one
reconcile-on-Suspended, one multi-server re-order (re-home), one client-area
view + self-service reset, and the release archive sanity check.
