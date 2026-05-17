# Continuum WHMCS Provisioning Module

Continuum WHMCS Provisioning Module connects WHMCS service lifecycle events to
the Continuum admin API. It creates users, suspends and restores access,
updates plan attributes, resets passwords, and gives staff a direct status view
from the WHMCS service page.

## Features

- Create Continuum users when WHMCS provisions a service.
- Suspend, unsuspend, and terminate services by toggling the Continuum user's
  `enabled` state. **Terminate disables, it does not delete** — see
  [Service Lifecycle](#service-lifecycle).
- Reconcile Continuum attributes from WHMCS product settings and configurable
  options.
- Keep Continuum email and username aligned with WHMCS.
- Link each WHMCS service to Continuum by stored user ID, with email and
  username fallback recovery.
- Generated usernames by default, or optional customer-chosen usernames at
  order time.
- Validate customer-chosen usernames for format, reserved names, blocked words,
  and uniqueness.
- **Auto-create** the internal custom fields it needs — no manual setup.
- **Scaffold** a ready-to-price configurable-option group with one click.
- **Test Connection** button on the Servers page.
- Every Continuum API call recorded in the WHMCS Module Log with secrets
  masked.
- Show Continuum status in the WHMCS admin service tab and a customer-facing
  panel in the client area.
- Optional WHMCS daily cron hook that logs basic drift.

## Requirements

- WHMCS 8.x.
- PHP 8.0 or newer.
- PHP JSON extension.
- PHP cURL extension, or `allow_url_fopen` enabled for HTTPS streams.
- Outbound HTTPS access from WHMCS to the Continuum server.
- A Continuum admin API key.

Composer is not required. The module is plain PHP and ships with its own small
autoload file.

## Installation

Download the release archive named like:

```sh
continuum-whmcs-module-vX.Y.Z.tar.gz
```

Extract it into the WHMCS server modules directory as `continuum`:

```sh
mkdir -p /path/to/whmcs/modules/servers/continuum
tar -xzf continuum-whmcs-module-vX.Y.Z.tar.gz -C /path/to/whmcs/modules/servers/continuum
```

The final path should contain:

```text
/path/to/whmcs/modules/servers/continuum/continuum.php
/path/to/whmcs/modules/servers/continuum/hooks.php
/path/to/whmcs/modules/servers/continuum/autoload.php
```

## WHMCS Server Setup

In Continuum, create an admin API key.

In WHMCS, go to `System Settings -> Servers -> Add New Server` and create a
server with these values:

| Field | Value |
| --- | --- |
| Name | Any descriptive name, for example `Continuum` |
| Hostname | Your Continuum hostname, without `https://` |
| Port | Continuum's port. Leave at `443` (Secure) / `80` for the default. |
| Secure | Enabled for HTTPS |
| Username | Unused; any value is acceptable |
| Password / Access Hash | Continuum admin API key |
| Module | `continuum` |

The hostname field is tolerant: a leading `https://`, a trailing path, or an
embedded `:port` are normalized, and a non-default Port is honored (in
provisioning, the daily cron, and the client-edit sync alike).

After saving, use the **Test Connection** button on the Servers page to
verify the hostname, port, TLS, and API key in one step. A failure
message distinguishes an unreachable host from an authentication error.

## Product Setup

For each WHMCS product that should provision Continuum accounts, open:

`System Settings -> Products/Services -> Edit Product -> Module Settings`

Set the module name to `continuum`, then configure:

| Field | Description |
| --- | --- |
| Role | Continuum role, usually `user`; `admin` is also accepted. |
| Library IDs | Comma-separated Continuum library IDs, e.g. `1,3,5`. Leave blank to grant **all** libraries (including any added to Continuum later). |
| Max concurrent streams | Integer stream limit. |
| Max concurrent transcodes | Integer transcode limit. |
| Max profiles | Integer profile limit. Forced to a minimum of 1 (Continuum rejects 0). |
| Downloads allowed | Whether downloads are allowed. |
| Download transcode allowed | Defaults to **No**. Forced off whenever Downloads allowed is off. |
| Max playback quality | Blank for unrestricted, or `1080p`, or `4k`. Continuum only enforces these three; legacy `720p`/`480p` behave as `1080p`. |
| Create default profile on CreateAccount | Recommended: enabled. Continuum creates one ready-to-use viewing profile inside the new account; if it can't, Continuum rolls back the user. With it off, the customer logs in to an empty account and must create a profile. |
| Allow customer-chosen username | See [Username Behavior](#username-behavior). |

### Custom fields (auto-created)

The module **auto-creates** the service custom fields it needs, so no manual
setup is required. `continuum_user_id` and `continuum_library_names_cache` are
always **internal admin-only** fields, never on the order form. The third,
`desired_username`, depends on the **Allow customer-chosen username** option:

| Field Name | Purpose | On order form? |
| --- | --- | --- |
| `continuum_user_id` | Continuum linkage — written by the module | Never |
| `continuum_library_names_cache` | Library-name cache — written by the module | Never |
| `desired_username` | Customer/admin chosen username; blank → auto-generated | Only when **Allow customer-chosen username** is enabled |

They are created/reconciled the first time the module provisions or reconciles
a service on the product, and immediately for every continuum product when the
**Scaffold Configurable Options** admin button is used (so a product with no
service yet — e.g. before its first order — is fully prepped). Auto-creation is
idempotent. Toggling **Allow customer-chosen username** moves `desired_username`
on/off the order form on the next provision, reconcile, or scaffold; fields you
created yourself are never altered, and no field is ever WHMCS-"Required".

## Configurable Options

The module reads normal WHMCS configurable options by name, so admins use
WHMCS-native checkboxes, dropdowns, radio buttons, and quantity fields. Names
are case-insensitive and punctuation is ignored.

### One-click scaffolding

On any Continuum-backed service, the **Scaffold Configurable Options** admin
button creates a `Continuum Options` group containing the recognized options
below (plus a per-library opt-in checkbox for each live Continuum library),
**with `0.00` pricing in every currency**, and links it to every continuum
product. It is idempotent: existing options and any prices you set are never
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
| `Max Playback Quality` | Dropdown or radio | Sets playback quality. Continuum enforces only unrestricted, `1080p`, or `4k`; `720p`/`480p` map to `1080p`. |
| `4K Streaming` | Checkbox | When checked, sets playback quality to 4k. |
| `Library IDs` | Dropdown or radio | Appends library IDs from a value such as `3` or `3,5`. |
| `Libraries` | Dropdown or radio | Same as `Library IDs`. |
| `Library 3` | Checkbox | When checked, appends library ID `3`. |
| `Library ID 3` | Checkbox | Same as `Library 3`. |
| `Role` | Dropdown or radio | Sets role when the value is `user` or `admin`. |

Library access is **all libraries unless something is listed**. If the
product's `Library IDs` field is blank and no library configurable option
contributes an ID, the customer gets every library (including ones added to
Continuum later). As soon as any ID is listed — product field or configurable
option — access is restricted to exactly that set.

Examples:

- Checkbox `4K Streaming` = `Yes`: sets `max_playback_quality` to `4k`.
- Quantity `Extra Streams` = `2`: adds two streams to the product base.
- Dropdown `Library IDs` = `3,5`: adds libraries `3` and `5`.
- Checkbox `Library 7` = `Yes`: adds library `7`.

Values like `No`, `Off`, `False`, `0`, `None`, and empty strings are disabled.

## Username Behavior

By default the module generates usernames in the form `abcd123`: four lowercase
letters followed by three digits, retrying up to five times on collision.

When **Allow customer-chosen username** is enabled on the product, the
`desired_username` custom field is placed **on the order form** (optional, with
a built-in `^[a-z0-9_-]{3,32}$` validation pattern) so the customer can pick
their own username at checkout. The order-form timing is handled by the
**Scaffold Configurable Options** button (or the next provision/reconcile),
which prepares the field before any order is taken. When the option is
disabled, `desired_username` is an internal admin-only field instead.

Whoever sets it (customer at order, or an admin on the service), a non-empty
`desired_username` is validated:

- 3 to 32 characters.
- Lowercase letters, digits, underscores, and hyphens only.
- Not a reserved system name.
- Not present in the blocked-word list.
- Not already used by another Continuum user.

If it is blank or invalid handling falls back appropriately (blank → a username
is generated; invalid → the order/provision reports the validation error).

To override the blocked-word list, place a `bad_words.txt` file next to
`continuum.php` in the installed module directory. The override replaces the
default list in `data/bad_words.default.txt`.

## Service Lifecycle

| WHMCS action | Continuum effect |
| --- | --- |
| CreateAccount | Creates the user (or re-attaches an existing one), applies all attributes, optionally creates a default profile. |
| SuspendAccount | Sets the Continuum user `enabled = false`. |
| UnsuspendAccount | Sets `enabled = true`. |
| TerminateAccount | Sets `enabled = false`. **The Continuum user, profiles, and watch history are retained.** |
| ChangePackage / Upgrade / Reconcile | Re-applies all product + configurable-option attributes to Continuum. |
| ChangePassword / Reset Password | Updates the Continuum user's password. |

**Terminate does not delete the Continuum user** — it is functionally identical
to Suspend. Upside: a returning customer keeps their history (re-linked by
email/username on the next CreateAccount). Downside: terminated accounts and
their data persist in Continuum until an admin deletes them there manually,
which is a data-retention/GDPR consideration. There is no module option to
delete on termination; removal is a deliberate manual action in Continuum.

## Linkage And Recovery

Each WHMCS service is linked to Continuum through three signals, checked in
order:

1. `continuum_user_id` service custom field.
2. WHMCS client email, lowercased.
3. WHMCS service username.

When a hook finds a user through a fallback signal it repairs
`continuum_user_id`. Successful updates also push the WHMCS email and service
username back to Continuum, making WHMCS the source of truth for those fields.
A transient Continuum API error during fallback resolution is treated as
"unresolved" rather than crashing the hook.

## Admin Tools

On a Continuum-backed WHMCS service, staff can use:

- `Continuum status` tab: Continuum user ID, email, enabled state, role,
  libraries, stream limit, and an admin deep link.
- `Reconcile from WHMCS`: pushes the current WHMCS product and configurable
  option state to Continuum (also ensures custom fields exist).
- `Reset Password`: generates a strong password, updates Continuum, and writes
  it back to the WHMCS service password (WHMCS-encrypted).
- `Scaffold Configurable Options`: see [Configurable Options](#configurable-options).

## Client Area

The module renders `templates/clientarea.tpl` for the customer service page —
service state, stream limit, playback quality, library names ("All libraries"
when unrestricted), last-seen time, and a sign-in link. All output is escaped;
configuration problems show a generic message (details go to the activity log,
never to the customer).

## Diagnostics

Every Continuum API call is recorded in **WHMCS → Utilities → Logs → Module
Log** (method, URL, status, body). The admin API key and any password in a
payload are passed to WHMCS as mask values, so they are redacted in the log.
This makes opaque failures (wrong port, TLS, auth, CDN/WAF responses)
diagnosable without extra tooling.

## Daily Drift Logging

`hooks.php` registers a `DailyCronJob` hook that scans active services on
continuum servers and logs basic enabled-state drift to the WHMCS activity log.
This is logging only; it does not fix drift. Use `Reconcile from WHMCS` on the
affected service to correct it.

## Troubleshooting

### Custom field is not declared

Normally auto-created. If you see this, run `Reconcile from WHMCS` or
`Scaffold Configurable Options` on a service of that product, or add
`continuum_user_id` and `continuum_library_names_cache` by hand.

### No Continuum user is linked

The Continuum user may have been deleted, or all linkage signals changed
outside WHMCS. Recreate/correct the Continuum user so the email or username
matches WHMCS, then run `Reconcile from WHMCS`. If you know the Continuum user
ID, set `continuum_user_id` on the service and reconcile.

### Continuum returned an HTTP error

Check the Module Log entry for the exact URL/response. `401/403` = API key;
`404` from a CDN/WAF often means an HTML error page rather than the API; `5xx`
= Continuum server. Also verify the configured hostname, port, and API key
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
  -czf dist/continuum-whmcs-module-vX.Y.Z.tar.gz .
```

Upload the resulting archive as the GitHub release asset.

## Development

The public module is dependency-free. Basic syntax validation:

```sh
find . -path './.git' -prune -o -path './dist' -prune -o -name '*.php' -print -exec php -l {} \;
```

Release validation should still include a staging WHMCS install before
publishing the packaged archive.

## Security Notes

### Credential handling

- The **Continuum admin API key** is never stored by the module. It lives only
  where WHMCS keeps it (encrypted in the server Password / Access Hash field),
  is read transiently per request, and is sent only as the `Authorization`
  header to Continuum.
- **User passwords** are not stored at rest by the module. On CreateAccount /
  ChangePassword the password is forwarded from WHMCS to Continuum in memory
  only. `Reset Password` writes the new password back to the WHMCS service
  password field, which WHMCS stores encrypted — that is the only persistence,
  and it is WHMCS's standard encrypted store, not a module-specific one.
- In the Module Log the API key and payload passwords are masked.
- Passwords are unavoidably cleartext in memory during a request and in transit
  over HTTPS to Continuum.

### General

- Store the Continuum admin API key only in the WHMCS server Password / Access
  Hash field. Use HTTPS for Continuum.
- Keep `bad_words.txt`, local environment files, and release archives out of
  git.
- Treat WHMCS module/activity logs as sensitive operational context.

## License

Proprietary. All rights reserved.
