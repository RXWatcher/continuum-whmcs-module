# WHMCS contract verification

**Status: 5 of 9 items VERIFIED via developers.whmcs.com on 2026-05-13.**
The remaining items (§3, §7, §8, §9) need confirmation against the target
WHMCS install during the pre-deploy smoke (Phase 14.2).

## 1. `$params` shape per hook — VERIFIED ✓

Citation: [WHMCS Module Parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/).

- `serverhostname`, `serversecure`, `serverpassword` set on every hook.
- `serviceid` present on lifecycle and admin button handlers.
- `clientsdetails` is an array of client fields (firstname, lastname, email, …).
- `password` provided on CreateAccount and ChangePassword (cleartext).
- `customfields` keyed by field NAME (confirmed).
- `configoption1..configoption24` for per-product config values.
- `configoptions` keyed by configurable-option NAME → selected VALUE.

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
may need to live as a per-product `configoption12` or in a separate
admin setting.

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

---

## Items still to verify pre-deploy

- §3: the `customfield` dict shape on the target WHMCS version.
- §7: the `reconcile_daily` server-form field surfacing.
- §8: `serviceusername` / `servicepassword` write-back persists, and a
  non-default server-form port surfaces as `$params['serverport']`.
- §9: scaffolded configurable options + auto-created custom fields render
  correctly on the order form, and `configoption10` is still "Allow
  customer-chosen username".

All can be checked by running one CreateAccount, one Reset Password, one
scaffold, and one daily-cron manually against staging and inspecting the
result.
