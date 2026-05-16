# WHMCS contract verification

**Status: 5 of 7 items VERIFIED via developers.whmcs.com on 2026-05-13.**
The remaining items (§3, §7) need confirmation against the target WHMCS
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

---

## Items still to verify pre-deploy

- §3: the `customfield` dict shape on the target WHMCS version.
- §7: the `reconcile_daily` server-form field surfacing.

Both can be checked by running one CreateAccount and one daily-cron
manually against staging and inspecting the result.
