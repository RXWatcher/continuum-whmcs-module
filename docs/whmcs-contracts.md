# WHMCS contract verification

**Status: 5 of the original 9 items VERIFIED via developers.whmcs.com on
2026-05-13.** §10 (multi-server re-home) was added later. The remaining
items (§3, §7, §8, §9, §10) need confirmation against the target WHMCS
install during the pre-deploy smoke (Phase 14.2).

## 1. `$params` shape per hook — VERIFIED ✓

Citation: [WHMCS Module Parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/).

- `serverhostname`, `serversecure`, `serverpassword` set on every hook.
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

  **Consequence:** any handler that must assert the Continuum user's
  `enabled` state (Continuum's `updateUser` is a partial PATCH — see
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
service through CreateAccount and verify `continuum_user_id` is populated
correctly.

## 4. Custom-button handler return type — VERIFIED ✓ + FIXED ✓

Citation: [WHMCS Custom Functions](https://developers.whmcs.com/provisioning-modules/custom-functions/) + [WHMCS Custom Actions](https://developers.whmcs.com/provisioning-modules/custom-actions/).

Button handlers registered via `AdminCustomButtonArray` MUST return a
status string (`'success'` or an error message). The previous return
shape `['redirect' => …, 'newWindow' => true]` is undocumented and not
recognised by WHMCS — the button silently does nothing.

The newer **Custom Actions** feature (WHMCS 8.5+) supports
`['success' => true, 'redirectTo' => 'url']` but has no `newWindow` flag.

**Fix:** The "Open in Continuum" deep-link no longer goes through a custom
button. It renders as a regular `<a target="_blank" rel="noopener">`
inside the `AdminServicesTabFields` output, where arbitrary HTML is
supported (commit `7c3fdbb`).

## 5. `ClientArea` return shape — VERIFIED ✓

Citation: [WHMCS Client Area Output](https://developers.whmcs.com/provisioning-modules/client-area-output/).

```
['templatefile' => 'clientarea', 'vars' => [ ... ]]
```

Resolves to `modules/servers/<modulename>/templates/clientarea.tpl`. The
module's existing return shape matches.

## 6. `ClientEdit` hook payload — VERIFIED ✓

Citation: [WHMCS hooks reference (client)](https://developers.whmcs.com/hooks-reference/client/).

`$vars` includes `userid`, `email`, `firstname`, `lastname`, and an
`olddata` sub-array of the previous values. The handler in `hooks.php`
compares `email` vs `olddata.email` to detect renames.

## 7. Server-level `reconcile_daily` flag location — UNVERIFIED

WHMCS's standard server form fields are limited. Whether extra named
fields surface in `$params` depends on the version. Confirm during the
pre-deploy smoke that toggling `reconcile_daily` on the server form
actually reaches `ServerConfig::fromParams`.

**Risk:** if WHMCS doesn't surface extra named server fields, the flag
may need to live as a per-product config option or in a separate admin
setting. (Note: `configoption11`/`12` are now taken by
`delete_on_terminate` / `auto_rehome_on_reorder`; the next free slot is
`configoption13`.)

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

Opt-in (`configoption12`, default OFF). When ON, `CreateAccount` may
move a service to the Continuum server that already hosts the returning
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
  `type='continuum'`, skips rows with `disabled = 1`, and builds a
  per-server client from `hostname`/`port`/`secure`/`decrypt(password)`
  — the same shape `hooks.php` (DailyCronJob/ClientEdit) already relies
  on. **Verify:** `tblservers.disabled` truthiness (1 = disabled) and
  that `decrypt()` is callable from the `CreateAccount` path on the
  target version (it is in cron/`ClientEdit`; CreateAccount is a new
  caller).
- **Pointer table.** `HomeStore` creates `mod_continuum_home` on demand
  via `Capsule::schema()` (same direct-schema philosophy as §9). It is a
  pure cache: every method is best-effort and degrades to a full scan,
  so a missing schema builder or insufficient `CREATE` grant only costs
  a re-scan, never correctness. **Verify:** the table is created (or the
  DB user *can* create it) on the target; otherwise note that re-home
  runs scan-only.

**Risk / to verify (end-to-end):** with `auto_rehome_on_reorder` ON and
`delete_on_terminate` OFF — terminate a service on server A, place a
**new** order that WHMCS routes to server B, and confirm: the service
ends up on **A**, the existing Continuum user is re-enabled with history
intact, no fresh account was created on B, the `mod_continuum_home`
pointer row exists, and a follow-up hook (e.g. Suspend) operates on A.

---

## Items still to verify pre-deploy

- §3: the `customfield` dict shape on the target WHMCS version.
- §7: the `reconcile_daily` server-form field surfacing.
- §8: `serviceusername` / `servicepassword` write-back persists, and a
  non-default server-form port surfaces as `$params['serverport']`.
- §9: scaffolded configurable options + auto-created custom fields render
  correctly on the order form, and `configoption10` is still "Allow
  customer-chosen username".
- §1: the `enabled`-state assertion. (a) Terminate a service with
  `delete_on_terminate=OFF`, re-order on the **same** server, and confirm
  the Continuum user comes back **enabled** — not merely relinked. (b) Run
  "Reconcile from WHMCS" against a **Suspended** service and confirm the
  user stays **disabled** (the fail-safe default must not resurrect a
  non-payer). (c) Confirm `Capsule` can read `tblhosting.domainstatus`
  from within the `ChangePackage` hook on the target WHMCS version.
- §10: multi-server re-home end-to-end (terminate on A → new order routed
  to B → service ends on A, user re-enabled, history intact, pointer row
  written, follow-up hook operates on A); `tblservers.disabled`
  semantics; `decrypt()` callable from `CreateAccount`; `Capsule::schema()`
  can create `mod_continuum_home` (or the DB user can).

All can be checked by running one CreateAccount, one Reset Password, one
scaffold, one daily-cron, one terminate→same-server re-order, one
reconcile-on-Suspended, and one multi-server re-order (re-home) manually
against staging and inspecting the result.
