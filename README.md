# Silo WHMCS Provisioning Module

Silo WHMCS Provisioning Module connects WHMCS service lifecycle events to
the Silo admin API. It creates users, suspends and restores access,
updates plan attributes, resets passwords, and gives staff a direct status view
from the WHMCS service page.

## Features

- Create Silo users when WHMCS provisions a service.
- Suspend and unsuspend by toggling the Silo user's `enabled` state.
  **Terminate deletes the Silo user by default** (configurable) — see
  [Service Lifecycle](#service-lifecycle).
- Reconcile Silo attributes from WHMCS product settings and configurable
  options.
- Keep Silo email and username aligned with WHMCS.
- Link each WHMCS service to Silo by stored user ID, with email and
  username fallback recovery.
- Generated usernames by default, or optional customer-chosen usernames at
  order time.
- Validate customer-chosen usernames for format, reserved names, blocked words,
  and uniqueness.
- **Auto-create** the internal custom fields it needs — no manual setup.
- **Scaffold** a ready-to-price configurable-option group with one click.
- **Test Connection** button on the Servers page.
- Every Silo API call recorded in the WHMCS Module Log with secrets
  masked.
- Show Silo status in the WHMCS admin service tab, and a customer-facing
  client-area panel (plan, profiles, live streams) with a self-service
  "reset password & sign out everywhere" action.
- WHMCS daily cron hook that logs drift across every attribute (limits,
  libraries, downloads, playback quality, enabled state).

## Requirements

- WHMCS 8.x.
- PHP 8.1 or newer.
- PHP JSON extension.
- PHP cURL extension, or `allow_url_fopen` enabled for HTTPS streams.
- Outbound HTTPS access from WHMCS to the Silo server.
- A Silo admin API key.

Composer is not required. The module is plain PHP and ships with its own small
autoload file.

## Installation

Download the release archive named like:

```sh
silo-whmcs-module-vX.Y.Z.tar.gz
```

Extract it into the WHMCS server modules directory as `silo`:

```sh
mkdir -p /path/to/whmcs/modules/servers/silo
tar -xzf silo-whmcs-module-vX.Y.Z.tar.gz -C /path/to/whmcs/modules/servers/silo
```

The final path should contain:

```text
/path/to/whmcs/modules/servers/silo/silo.php
/path/to/whmcs/modules/servers/silo/hooks.php
/path/to/whmcs/modules/servers/silo/autoload.php
```

## WHMCS Server Setup

In Silo, create an admin API key.

In WHMCS, go to `System Settings -> Servers -> Add New Server` and create a
server with these values:

| Field | Value |
| --- | --- |
| Name | Any descriptive name, for example `Silo` |
| Hostname | Your Silo hostname, without `https://` |
| Port | Silo's port. Leave at `443` (Secure) / `80` for the default. |
| Secure | Enabled for HTTPS |
| Username | Unused; any value is acceptable |
| Password / Access Hash | Silo admin API key |
| Module | `silo` |

The hostname field is tolerant: a leading `https://`, a trailing path, or an
embedded `:port` are normalized, and a non-default Port is honored (in
provisioning, the daily cron, and the client-edit sync alike).

After saving, use the **Test Connection** button on the Servers page to
verify the hostname, port, TLS, and API key in one step. A failure
message distinguishes an unreachable host from an authentication error.

## Product Setup

For each WHMCS product that should provision Silo accounts, open:

`System Settings -> Products/Services -> Edit Product -> Module Settings`

Set the module name to `silo`, then configure:

Every Silo user is provisioned with the `user` role — the module
sells no admin accounts, so role is fixed and not a configurable field.

| Field | Description |
| --- | --- |
| Library IDs | Comma-separated Silo library IDs, e.g. `1,3,5`. Leave blank to grant **all** libraries (including any added to Silo later). |
| Max concurrent streams | Integer stream limit. |
| Max concurrent transcodes | Integer transcode limit. |
| Max profiles | Integer profile limit. Forced to a minimum of 1 (Silo rejects 0). |
| Downloads allowed | Whether downloads are allowed. |
| Download transcode allowed | Defaults to **No**. Forced off whenever Downloads allowed is off. |
| Max playback quality | Blank for unrestricted, or `1080p`, or `4k`. Silo only enforces these three; legacy `720p`/`480p` behave as `1080p`. |
| Create default profile on CreateAccount | Recommended: enabled. Silo creates one ready-to-use viewing profile inside the new account; if it can't, Silo rolls back the user. With it off, the customer logs in to an empty account and must create a profile. |
| Allow customer-chosen username | See [Username Behavior](#username-behavior). |
| Delete Silo user on termination | **Default ON.** ON: terminating the service permanently deletes the Silo user (profiles + watch history; cannot be undone). OFF: termination only disables the user (data retained; a re-order re-links only if it resolves to the same Silo server — see [Service Lifecycle](#service-lifecycle)). |
| Re-home returning customers (multi-server) | **Default OFF.** ON: a new order whose user already exists on another configured Silo server moves the service to that server and re-links the existing user instead of creating a fresh account. See [Re-home returning customers](#re-home-returning-customers-multi-server). |
| Allow client-area password reset | **Default ON.** ON: customers can use the client-area reset action; the generated password is shown once and written to the WHMCS service. OFF: only staff can reset passwords from the admin service page. |

### Custom fields (auto-created)

The module **auto-creates** the service custom fields it needs, so no manual
setup is required. All three are created **internal admin-only and not on the
order form**:

| Field Name | Purpose | On order form? |
| --- | --- | --- |
| `silo_user_id` | Silo linkage — written by the module | Never |
| `silo_library_names_cache` | Library-name cache — written by the module | Never |
| `desired_username\|Enter your desired username` | Chosen username; blank → auto-generated | No by default — an admin enables it manually (see [Username Behavior](#username-behavior)) |

They are created the first time the module provisions or reconciles a service
on the product, and immediately for every silo product when the
**Scaffold Configurable Options** admin button is used (so a product with no
service yet — e.g. before its first order — is fully prepped). Creation is
**create-if-missing only**: once a field exists the module never changes it, so
any edit you make (including ticking *Show on Order Form* for
`desired_username`) is never undone. No field is ever WHMCS-"Required".

## Configurable Options

The module reads normal WHMCS configurable options by name, so admins use
WHMCS-native checkboxes, dropdowns, radio buttons, and quantity fields. Name
matching is case-insensitive and any punctuation/separators are treated as
spaces — so `Library IDs`, `library-ids`, and `library_ids` all match, but a
run-together `LibraryIDs` (no separator) does **not**.

### One-click scaffolding

On any Silo-backed service, the **Scaffold Configurable Options** admin
button creates a `Silo Options` group with a curated starter set —
`Extra Streams`, `Extra Transcodes`, `Extra Profiles` (quantity),
`4K Streaming`, `Downloads Allowed`, `Download Transcode Allowed` (yes/no),
`Max Playback Quality` (dropdown), plus a `Library N` opt-in
checkbox for each live Silo library — **with `0.00` pricing in every
currency**, and links it to every silo product. (It does not create every
recognized name below — e.g. the `Max Streams`/`Max Transcodes`/`Max Profiles`
overrides and the `Library IDs`/`Libraries` dropdowns are left for you to add
if wanted.) It is idempotent: existing options and any prices you set are never
overwritten. You only need to set prices, then the group is live. (Configurable
options are intentionally not auto-created on lifecycle hooks — they are
optional upsells whose pricing the module must not invent.)

### Recognized option names

| Configurable Option Name | WHMCS Control | Behavior |
| --- | --- | --- |
| `Extra Streams` | Quantity or dropdown | Adds the selected number to `Max concurrent streams`. |
| `Max Streams` | Quantity or dropdown | Replaces `Max concurrent streams`. |
| `Extra Transcodes` | Quantity or dropdown | Adds to `Max concurrent transcodes`. |
| `Max Transcodes` | Quantity or dropdown | Replaces `Max concurrent transcodes`. |
| `Extra Profiles` | Quantity or dropdown | Adds to `Max profiles`. |
| `Max Profiles` | Quantity or dropdown | Replaces `Max profiles`. |
| `Downloads Allowed` | Checkbox or dropdown | Overrides download access. |
| `Download Transcode Allowed` | Checkbox or dropdown | Overrides download-transcode access (still forced off if downloads are off). |
| `Max Playback Quality` | Dropdown or radio | Sets playback quality. Silo enforces only unrestricted, `1080p`, or `4k`; `720p`/`480p` map to `1080p`. |
| `4K Streaming` | Checkbox | When checked, sets playback quality to 4k. |
| `Library IDs` | Dropdown / radio / quantity | Adds **every number found in the selected value** as a library ID. Dropdown/radio is single-select, so one choice only contributes the IDs in that one value (e.g. a sub-option labelled `3,5` → libraries 3 and 5; `Movies (3)` → library 3). |
| `Libraries` | Dropdown / radio / quantity | Same as `Library IDs` (aliases: `Library Access`, `Library Pack`). |
| `Library 3` | Checkbox | When checked, appends library ID `3`. **Use these for per-library opt-in** — one checkbox per library lets customers enable libraries individually. |
| `Library ID 3` | Checkbox | Same as `Library 3`. |

Library access is **all libraries unless something is listed**. If the
product's `Library IDs` field is blank and no library configurable option
contributes an ID, the customer gets every library (including ones added to
Silo later). As soon as any ID is listed — product field or configurable
option — access is restricted to exactly that set.

Examples:

- Checkbox `4K Streaming` = `Yes`: sets `max_playback_quality` to `4k`.
- Quantity `Extra Streams` = `2`: adds two streams to the product base.
- Checkbox `Library 7` = `Yes`: adds library `7` (the per-library opt-in pattern).
- Dropdown `Libraries` with a sub-option labelled `Movies & Anime (3,5)`
  selected: adds libraries `3` and `5` (the digits in that one chosen value).

To let customers pick libraries individually, create one `Library N` checkbox
per library — a single dropdown/radio can only select one value. Caveat: for
`Library IDs`/`Libraries`, **any** digits in the chosen value are treated as
library IDs, so don't put unrelated numbers in those option/value labels.

Values like `No`, `Off`, `False`, `0`, `None`, and empty strings are disabled.

## Username Behavior

By default the module generates usernames in the form `abcd123`: four lowercase
letters followed by three digits, retrying up to five times on collision.

The module auto-creates the field as
`desired_username|Enter your desired username` (no spaces around the `|` — WHMCS
does not trim it) — **admin-only and not on the order form by default**. To collect it from customers, an admin ticks
*Show on Order Form* on that field (and enables **Allow customer-chosen
username** so the module consults it). The module never moves the field on or
off the form, so that manual choice is never reverted.

The field is found by logical name regardless of its WHMCS label, so you may
rename or relabel it freely. (WHMCS keys `customfields` by the text *after* a
`|` in the field name — e.g. `Enter your desired username` — not by
`desired_username`; the module resolves it tolerantly so this does not break
the feature.)

When **Allow customer-chosen username** is enabled, a non-empty
`desired_username` (set by the customer at order, or by an admin on the
service) is validated:

- 3 to 12 characters.
- Lowercase letters, digits, underscores, and hyphens only.
- Not a reserved system name.
- Not present in the blocked-word list.
- Not already used by another Silo user.

If it is blank or invalid handling falls back appropriately (blank → a username
is generated; invalid → the order/provision reports the validation error).

To override the blocked-word list, place a `bad_words.txt` file next to
`silo.php` in the installed module directory. The override replaces the
default list in `data/bad_words.default.txt`.

## Service Lifecycle

| WHMCS action | Silo effect |
| --- | --- |
| CreateAccount | Creates the user (or re-attaches an existing one), applies all attributes, optionally creates a default profile. |
| SuspendAccount | Sets the Silo user `enabled = false`. |
| UnsuspendAccount | Sets `enabled = true`. |
| TerminateAccount | **Default: deletes the Silo user** (profiles + watch history). If "Delete Silo user on termination" is OFF, only sets `enabled = false` and retains everything. |
| ChangePackage / Upgrade / Reconcile | Re-applies all product + configurable-option attributes to Silo. |
| ChangePassword / Reset Password | Updates the Silo user's password. Silo revokes all of that user's sessions on a password change, so this also signs them out everywhere. |

Termination is governed by the **Delete Silo user on termination** product
option (**default ON**):

- **ON (default):** the Silo user is permanently deleted on terminate —
  profiles and watch history included. This **cannot be undone**. A user that
  is already gone (HTTP 404) is treated as success; if no user is linked,
  termination still succeeds.
- **OFF:** terminate only disables the user (functionally identical to
  Suspend). The account and history are retained, and a returning customer is
  re-linked **and re-enabled** on the next CreateAccount or Reconcile —
  **provided it resolves to the same Silo server**. Linkage resolution is
  per-server (see below): reactivating the *same* WHMCS service always works,
  because it keeps the `silo_user_id` linkage and server assignment; a
  *brand-new* order is matched only by email/username, so in a multi-server
  group it can be routed to a different server where the disabled user does
  not exist — there a fresh account is created and the old history is **not**
  recovered. To rely on retention with multiple servers, either enable
  **Re-home returning customers** (below), pin re-orders back to the
  originating server (specific-server / one-server-per-product), or treat
  returns as reactivations rather than new orders. Removal then requires a
  deliberate manual action in Silo — a data-retention/GDPR
  consideration.

Suspend and Unsuspend never delete, regardless of this option.

### Re-home returning customers (multi-server)

The **Re-home returning customers** product option (`auto_rehome_on_reorder`,
**default OFF**) closes the multi-server gap above. When ON, a brand-new order
whose user can't be resolved on the assigned server triggers a cross-server
lookup (every active silo-typed WHMCS server, by email then username,
cached in a `mod_silo_home` pointer table). If the customer's existing
Silo user is found on another server, the WHMCS service is **moved to
that server** and the existing user is re-linked and re-enabled — preserving
profiles and watch history — instead of creating a fresh account. The move
tries WHMCS's `UpdateClientProduct` (`serverid`) first, then **verifies it and
falls back to a direct `tblhosting` write** if that WHMCS version ignores the
parameter — so it works regardless of version.

- A genuine new customer (found nowhere) is created normally; the chosen home
  is recorded so future re-orders are deterministic and cheap.
- If a home exists but the move fails, provisioning returns a descriptive
  error rather than silently creating a fresh account and orphaning history.
- Users only reachable on a disabled server fall back to a fresh account.
- No effect with a single server or a shared Silo backend.

The home pointer is kept in sync with reality automatically: a terminate
with "Delete Silo user on termination" ON drops the pointer (no
account left to re-home to), and a WHMCS `ClientEdit` email rename moves
the pointer to the new email key (so multi-server re-home keeps probing
the right server for that customer). If WHMCS's `UpdateClientProduct`
ignores `serverid` on your version and the module falls back to a direct
`tblhosting.server` write, that fallback is logged to the activity log so
the audit trail explains what re-pointed the service.

## Linkage And Recovery

Each WHMCS service is linked to Silo through three signals, checked in
order:

1. `silo_user_id` service custom field — **verified**: the returned
   user's email must match the WHMCS client email (case-insensitive). A
   stale or hand-edited ID pointing at a different customer falls through
   to tier 2 instead of letting subsequent writes (rename, password
   reset, suspend) hit the wrong account. Silo responses that omit
   the email field are treated as unverifiable and accepted.
2. WHMCS client email, lowercased.
3. WHMCS service username.

When a hook finds a user through a fallback signal it repairs
`silo_user_id`. Successful updates also push the WHMCS email and service
username back to Silo, making WHMCS the source of truth for those fields.

Failure handling differs by handler intent:

- **Display / idempotent paths** (`ClientArea`, `AdminServicesTab`,
  `SetEnabled`, `ChangePackage`, etc.) treat a Silo API error during
  fallback resolution as "unresolved" rather than crashing the hook —
  the page or action degrades gracefully.
- **`CreateAccount`** uses strict resolution: if Silo is unreachable
  while scanning for an existing user, the hook **refuses to provision**
  rather than risk creating a duplicate. WHMCS retries the hook once
  Silo recovers. Additionally, if `createUser` itself fails with a
  network/5xx error, the module looks the user up by email post-failure
  and links to the recovered record — so a "response lost in flight"
  network blip can never produce two Silo accounts for one order.

Resolution is **per-server**: every hook runs against the one Silo
server WHMCS assigned to that service, and the fallbacks (email/username)
only search that server's users — there is no cross-server lookup. This is
why retain-on-terminate recovery depends on a re-order landing back on the
originating server (see **Service Lifecycle → OFF** above).

## Admin Tools

On a Silo-backed WHMCS service, staff can use:

- `Silo status` tab: Silo user ID, username, email, enabled
  state, role, libraries (or "All libraries (unrestricted)"), stream /
  transcode / profile limits, downloads + download-transcode access, max
  playback quality, last seen, and an admin deep link — full parity with
  the customer-facing client area so a reconcile can be visually
  verified without leaving WHMCS.
- `Reconcile from WHMCS`: pushes the current WHMCS product and configurable
  option state to Silo (also ensures custom fields exist).
- `Reset Password`: generates a strong password, updates Silo, and writes
  it back to the WHMCS service password (WHMCS-encrypted). Also signs the
  customer out of all devices (Silo revokes sessions on a password
  change). Customers can self-serve the same action from the client area
  when **Allow client-area password reset** is ON.
- `Scaffold Configurable Options`: see [Configurable Options](#configurable-options).

## Client Area

The module renders `templates/clientarea.tpl` for the customer service page:

- **Status & identity** — active/suspended badge, Silo username, member-since.
- **Plan** — concurrent streams, transcodes, profile limit, playback quality,
  download access.
- **Live usage** — profiles used vs. limit (with names), and "watching now"
  (active streams vs. limit, with titles). Stream/profile counts come from
  `/admin/sessions` and `/admin/users/{id}/profiles`; **`client_ip` and other
  PII are never surfaced** — only titles/counts.
- **Libraries** — names ("All libraries" when unrestricted), last-seen time,
  and a sign-in link.

Self-service: a **Reset password & sign out all devices** button when
**Allow client-area password reset** is ON (default). A Silo admin password
change also revokes every session server-side, so this one action both
rotates the password and signs the customer out everywhere. The generated
password is shown once in WHMCS and written to the service password; turn
the option OFF if staff should handle resets instead.

Each enrichment (`getUser`, profiles, sessions) degrades **independently** —
if one Silo call fails the rest of the page still renders. All output is
escaped; configuration problems show a generic message (details go to the
activity log, never to the customer). Note this adds up to ~3 admin-API calls
per page view (library names stay cached 24h via the custom field).

## Diagnostics

Every Silo API call is recorded in **WHMCS → Utilities → Logs → Module
Log** (method, URL, status, body). The admin API key and any password in a
payload are passed to WHMCS as mask values, so they are redacted in the log.
This makes opaque failures (wrong port, TLS, auth, CDN/WAF responses)
diagnosable without extra tooling.

## Daily Drift Logging

`hooks.php` registers a `DailyCronJob` hook that scans active services on
every silo-typed server and reports drift to the WHMCS activity log.
The check covers every attribute the module manages — `enabled`, `role`,
`library_ids`, `max_streams`, `max_transcodes`, `max_profiles`,
`download_allowed`, `download_transcode_allowed`, and
`max_playback_quality` — by rebuilding the expected state from the
service's product config + configurable options and comparing it against
Silo's observed user record.

The cron runs unconditionally on every Silo-typed server (there is
no per-server opt-out today). This is logging only; it does not fix
drift. Use `Reconcile from WHMCS` on the affected service to correct it.

## Troubleshooting

### Custom field is not declared

Normally auto-created. If you see this, run `Reconcile from WHMCS` or
`Scaffold Configurable Options` on a service of that product, or add
`silo_user_id` and `silo_library_names_cache` by hand.

### No Silo user is linked

The Silo user may have been deleted, or all linkage signals changed
outside WHMCS. Recreate/correct the Silo user so the email or username
matches WHMCS, then run `Reconcile from WHMCS`. If you know the Silo user
ID, set `silo_user_id` on the service and reconcile.

### Silo returned an HTTP error

Check the Module Log entry for the exact URL/response. `401/403` = API key;
`404` from a CDN/WAF often means an HTML error page rather than the API; `5xx`
= Silo server. Also verify the configured hostname, port, and API key
(the **Test Connection** button checks all of these).

### Username is already taken

Choose another. For generated usernames the module retries collisions up to
five times.

## Building A Release Archive

The module has no Composer build step. Create a release archive from a clean
checkout:

```sh
mkdir -p dist
tar \
  --exclude='./.git' \
  --exclude='./dist' \
  --exclude='./.gitignore' \
  --exclude='./.claude' \
  --exclude='./.env' \
  --exclude='./.env.*' \
  --exclude='./bad_words.txt' \
  --exclude='./.phpunit.result.cache' \
  -czf dist/silo-whmcs-module-vX.Y.Z.tar.gz .
```

Upload the resulting archive as the GitHub release asset.

## Development

The **shipped** module is dependency-free — Composer and PHPUnit are dev
tooling only (`vendor/` is gitignored and never part of the release
archive). Basic syntax validation:

```sh
find . -path './.git' -prune -o -path './dist' -prune -o -name '*.php' -print -exec php -l {} \;
```

### Test suite

```sh
composer install      # one-time; pulls PHPUnit into vendor/ (dev only)
composer test         # or: vendor/bin/phpunit
```

Tests run with no WHMCS or Silo install: `tests/Support/` shims the
WHMCS runtime (`localAPI`, `Capsule`, `logActivity`, …) and a
`FakeClient` stands in for the Silo HTTP API, so handlers exercise
the real `Identity`/`AttributeMapper`/`CustomFieldStore` code. Coverage
spans every service-lifecycle and admin handler (Create/Change/
Terminate/SetEnabled, ChangePassword, AdminResetPassword,
ClientResetPassword, TestConnection, ClientArea, AdminServicesTab,
ScaffoldOptions, DailyReconciler) and the pure logic
(Identity, AttributeMapper, ProductConfig/ServerConfig, PlaybackQuality,
Username validation/generation, BadWordList, DriftCheck, HomeStore,
ConfigOptionScaffolder) — including the status-aware `enabled` assertion
in `CreateAccount`/`ChangePackage`, the tier-1 identity verification, and
the `createUser` idempotency recovery on transient errors.

CI runs the suite on every push and PR (`.github/workflows/tests.yml`,
PHP 8.2–8.4) plus a `php -l` lint at the 8.1 runtime floor.

Release validation should still include a staging WHMCS install before
publishing the packaged archive — see the pre-deploy smoke in
`docs/whmcs-contracts.md`.

## Security Notes

### Credential handling

- The **Silo admin API key** is never stored by the module. It lives only
  where WHMCS keeps it (encrypted in the server Password / Access Hash field),
  is read transiently per request, and is sent only as the `Authorization`
  header to Silo.
- **User passwords** are not stored at rest by the module. On CreateAccount /
  ChangePassword the password is forwarded from WHMCS to Silo in memory
  only. Both the admin `Reset Password` button and the customer's client-area
  "reset password & sign out" action write the new password back to the WHMCS
  service password field, which WHMCS stores encrypted — that is the only
  persistence, and it is WHMCS's standard encrypted store, not a
  module-specific one. The client-area action additionally shows the new
  password once in the returned confirmation message (so the customer can
  capture it); it is not persisted anywhere else by the module.
- In the Module Log the API key and payload passwords are masked.
- Passwords are unavoidably cleartext in memory during a request and in transit
  over HTTPS to Silo.

### General

- Store the Silo admin API key only in the WHMCS server Password / Access
  Hash field. Use HTTPS for Silo.
- Keep `bad_words.txt`, local environment files, and release archives out of
  git.
- Treat WHMCS module/activity logs as sensitive operational context.

## License

Proprietary. All rights reserved.
