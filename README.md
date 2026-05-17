# Continuum WHMCS Provisioning Module

Continuum WHMCS Provisioning Module connects WHMCS service lifecycle events to
the Continuum admin API. It creates users, suspends and restores access,
updates plan attributes, resets passwords, and gives staff a direct status view
from the WHMCS service page.

## Features

- Create Continuum users when WHMCS provisions a service.
- Suspend, unsuspend, and terminate services by toggling the Continuum user's
  `enabled` state. Termination does not delete the Continuum user.
- Reconcile Continuum attributes from WHMCS product settings and configurable
  options.
- Keep Continuum email and username aligned with WHMCS.
- Link each WHMCS service to Continuum by stored user ID, with email and
  username fallback recovery.
- Support generated usernames or customer-chosen usernames.
- Validate customer-chosen usernames for format, reserved names, blocked words,
  and uniqueness.
- Show Continuum status in the WHMCS admin service tab.
- Show a customer-facing service panel in the WHMCS client area.
- Optionally run a WHMCS daily cron hook to log basic drift.

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
| Library IDs | Comma-separated Continuum library IDs, for example `1,3,5`. Leave blank to grant **all** libraries (including any added to Continuum later). |
| Max concurrent streams | Integer stream limit. |
| Max concurrent transcodes | Integer transcode limit. |
| Max profiles | Integer profile limit. |
| Downloads allowed | Whether downloads are allowed. |
| Download transcode allowed | Whether download transcoding is allowed. |
| Max playback quality | Blank for unrestricted, or `1080p`, or `4k`. Continuum only enforces these; legacy `720p`/`480p` settings behave as `1080p`. |
| Create default profile on CreateAccount | Recommended: enabled. |
| Allow customer-chosen username | Enables the optional `desired_username` field. |

The module **auto-creates** the service custom fields it needs, so no
manual setup is required. All three are **internal admin-only** fields,
**never shown on the order form** and never WHMCS-"Required" — there are
no custom fields a customer must fill in:

| Field Name | Purpose |
| --- | --- |
| `continuum_user_id` | Continuum linkage — written by the module |
| `continuum_library_names_cache` | Library-name cache — written by the module |
| `desired_username` | Optional admin-set username; blank → auto-generated |

They are created the first time the module provisions or reconciles a
service on the product, and immediately for every continuum product when
the **Scaffold Configurable Options** admin button is used (handy for a
product that has no service yet). Auto-creation is idempotent and never
alters fields you created yourself.

`desired_username` is not collected from customers at order time. When
`Allow customer-chosen username` is enabled, an admin may set that field
on the service; if it is blank the module generates a username.

## Configurable Options

The module reads normal WHMCS configurable options by name. This lets admins use
WHMCS-native checkboxes, dropdowns, radio buttons, and quantity fields instead
of writing JSON.

Create configurable options in WHMCS with the names below. Names are
case-insensitive, and punctuation is ignored.

| Configurable Option Name | WHMCS Control | Behavior |
| --- | --- | --- |
| `Extra Streams` | Quantity or dropdown | Adds the selected number to `Max concurrent streams`. |
| `Max Streams` | Quantity or dropdown | Replaces `Max concurrent streams`. |
| `Extra Transcodes` | Quantity or dropdown | Adds the selected number to `Max concurrent transcodes`. |
| `Max Transcodes` | Quantity or dropdown | Replaces `Max concurrent transcodes`. |
| `Extra Profiles` | Quantity or dropdown | Adds the selected number to `Max profiles`. |
| `Max Profiles` | Quantity or dropdown | Replaces `Max profiles`. |
| `Downloads Allowed` | Checkbox or dropdown | Overrides download access. |
| `Download Transcode Allowed` | Checkbox or dropdown | Overrides download-transcode access. |
| `Max Playback Quality` | Dropdown or radio | Sets playback quality. Continuum enforces only unrestricted, `1080p`, or `4k`; `720p`/`480p` values map to `1080p`. |
| `4K Streaming` | Checkbox | When checked, sets playback quality to 4k. |
| `Library IDs` | Dropdown or radio | Appends library IDs from a value such as `3` or `3,5`. |
| `Libraries` | Dropdown or radio | Same as `Library IDs`. |
| `Library 3` | Checkbox | When checked, appends library ID `3`. |
| `Library ID 3` | Checkbox | Same as `Library 3`. |
| `Role` | Dropdown or radio | Sets role when the value is `user` or `admin`. |

Library access is **all libraries unless something is listed**. If the
product's `Library IDs` field is blank and no library configurable option
contributes an ID, the customer gets every library (including ones added
to Continuum later). As soon as any ID is listed — on the product field
or via a configurable option — access is restricted to exactly that set.

Examples:

- Checkbox named `4K Streaming`, value `Yes`: sets `max_playback_quality` to
  `4k`.
- Quantity option named `Extra Streams`, value `2`: adds two streams to the
  product's base stream limit.
- Dropdown named `Library IDs`, selected value `3,5`: adds libraries `3` and
  `5`.
- Checkbox named `Library 7`, value `Yes`: adds library `7`.

Values like `No`, `Off`, `False`, `0`, `None`, and empty strings are treated as
disabled.

## Username Behavior

By default, the module generates usernames in the form `abcd123`: four
lowercase letters followed by three digits.

If `Allow customer-chosen username` is enabled, a username set in the
service's admin-only `desired_username` custom field is used instead of a
generated one. (The field is not shown on the order form.) The module
validates the chosen username:

- 3 to 32 characters.
- Lowercase letters, digits, underscores, and hyphens only.
- Not a reserved system name.
- Not present in the blocked-word list.
- Not already used by another Continuum user.

To override the blocked-word list, place a `bad_words.txt` file next to
`continuum.php` in the installed module directory. The override replaces the
default list in `data/bad_words.default.txt`.

## Linkage And Recovery

Each WHMCS service is linked to Continuum through three signals, checked in
this order:

1. `continuum_user_id` service custom field.
2. WHMCS client email, lowercased.
3. WHMCS service username.

When a hook finds a user through a fallback signal, it repairs
`continuum_user_id`. Successful updates also push the WHMCS email and service
username back to Continuum, making WHMCS the source of truth for those fields.

## Admin Tools

On a Continuum-backed WHMCS service, staff can use:

- `Continuum status` tab: shows the Continuum user ID, email, enabled state,
  role, libraries, stream limit, and an admin deep link.
- `Reconcile from WHMCS`: pushes the current WHMCS product and configurable
  option state to Continuum.
- `Reset Password`: generates a strong password, updates Continuum, and writes
  the new password back to the WHMCS service.

## Client Area

The module renders `templates/clientarea.tpl` for the customer service page. It
shows service state, stream limit, playback quality, library names, last-seen
time, and a sign-in link to the Continuum server.

## Daily Drift Logging

`hooks.php` registers a `DailyCronJob` hook. In the current release it scans
active services on Continuum servers and logs basic enabled-state drift to the
WHMCS activity log.

This is logging only. It does not automatically fix drift. Staff should use
`Reconcile from WHMCS` on the affected service when correction is needed.

## Troubleshooting

### Custom field is not declared

Add the required custom fields to the WHMCS product:

- `continuum_user_id`
- `continuum_library_names_cache`

Then retry the module action.

### No Continuum user is linked

The Continuum user may have been deleted, or all linkage signals may have
changed outside WHMCS. Recreate or correct the Continuum user so that either
the email or username matches WHMCS, then run `Reconcile from WHMCS`.

If you know the correct Continuum user ID, you can also set
`continuum_user_id` on the WHMCS service custom field and reconcile.

### Continuum returned a server error

Continuum returned HTTP 5xx. Check:

- WHMCS Module Log.
- WHMCS Activity Log.
- Continuum server logs.
- The configured Continuum hostname and API key.

### Username is already taken

Choose another username. For generated usernames, the module retries collisions
up to five times.

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

The public module is dependency-free. Basic syntax validation can be run with
the PHP CLI:

```sh
find . -path './.git' -prune -o -path './dist' -prune -o -name '*.php' -print -exec php -l {} \;
```

Release validation should still include a staging WHMCS install before
publishing the packaged archive.

## Security Notes

- Store the Continuum admin API key only in the WHMCS server password/access
  hash field.
- Use HTTPS for Continuum.
- Keep `bad_words.txt`, local environment files, and release archives out of
  git.
- Treat WHMCS module logs as sensitive because failed API calls may contain
  operational context.

## License

Proprietary. All rights reserved.
