# continuum (WHMCS Provisioning Module)

A WHMCS Provisioning Module that creates, suspends, restores, and adjusts
Continuum user accounts in response to WHMCS service-lifecycle events.

**Status:** v0.1. See design spec for architecture details.

## Requirements

- WHMCS 8.x (tested against 8.6+)
- PHP 8.0 or later
- Composer (for installation)
- Outbound HTTPS access from WHMCS to your Continuum origin

## Install

1. Extract the release tarball to your WHMCS install:
   ```
   tar xzf continuum-whmcs-module-x.y.z.tar.gz -C <whmcs-root>/modules/servers/
   ```

2. From the extracted directory, install runtime dependencies:
   ```
   cd <whmcs-root>/modules/servers/continuum
   composer install --no-dev --optimize-autoloader
   ```

## Setup (one-time per Continuum instance)

### 1. Generate a Continuum admin API key

In Continuum: **Admin → API Keys → Create new key** with role `admin`. Copy the key.

### 2. Add a Server in WHMCS

**Setup → Products → Servers → Add New Server.**

| Field | Value |
|---|---|
| Name | "Continuum" (or whatever you call it) |
| Hostname | `continuum.example.com` (no scheme) |
| Secure | check the box (https) |
| Username | anything (unused) |
| Password / Access Hash | paste the API key from step 1 |
| Module | continuum |

### 3. Per-product setup

For each WHMCS product that should provision Continuum accounts:

**Setup → Products → edit product → Module Settings tab.**

Choose **Module Name: continuum**. The form below shows:

| Field | Meaning |
|---|---|
| Role | `user` (most cases) or `admin` |
| Library IDs | comma-separated Continuum library IDs the customer can access |
| Max concurrent streams | integer |
| Max concurrent transcodes | integer |
| Max profiles | integer |
| Downloads allowed | yes/no |
| Download transcode allowed | yes/no |
| Max playback quality | blank (unrestricted), 4k, 1080p, 720p, or 480p |
| Create default profile on CreateAccount | yes (recommended) — so first login isn't confusing |
| Allow customer-chosen username | yes/no (default no — every account gets a generated handle) |
| Configurable options mapping (JSON) | see below |

### 4. Service custom fields (required)

The module stores its linkage and cache in two WHMCS service custom fields.
**Both are required on every product** that uses this module. Without them,
`CreateAccount` will fail with a clear error.

**Setup → Products → edit product → Custom Fields tab.** Add:

| Name | Type | Show on Order Form |
|---|---|---|
| `continuum_user_id` | Text Box | No |
| `continuum_library_names_cache` | Text Box | No |

If the product uses customer-chosen usernames (`allow_user_chosen_username = yes`),
also add a third optional field:

| Name | Type | Show on Order Form |
|---|---|---|
| `desired_username` | Text Box | Yes |

### How linkage works

Each WHMCS service is linked to a Continuum user through **three** signals,
checked in order on every hook:

1. The `continuum_user_id` custom field (the canonical, stable key — set by
   CreateAccount and re-aligned automatically if it drifts).
2. The WHMCS client's email (lowercased before lookup, since Continuum
   stores emails case-sensitively).
3. The WHMCS service username (the one written back to the service record
   at CreateAccount time).

A single-field rename on either side does not break the link — the next
hook re-discovers the user via the surviving signal and rewrites the stale
field. The link only breaks if all three signals change on one side
without being mirrored on the other. Every successful hook also pushes the
WHMCS email and username back to Continuum, so WHMCS is the source of
truth.

## Configurable options mapping

The "Configurable options mapping" field on a product is a JSON array of rules
that modify Continuum attributes based on WHMCS configurable options the
customer selects at checkout. Example:

```json
[
  {"option_name": "Extra Streams", "match": "5", "attribute": "max_streams", "op": "add", "value": 5},
  {"option_name": "Extra Streams", "match": "10", "attribute": "max_streams", "op": "add", "value": 10},
  {"option_name": "4K Streaming",  "match": "Yes", "attribute": "max_playback_quality", "op": "set", "value": "4k"},
  {"option_name": "Library Pack A", "match": "Yes", "attribute": "library_ids", "op": "append", "value": [3, 5]}
]
```

### Operators

- `set` — overwrite (last-write-wins if multiple set rules hit the same attribute).
- `add` — add to the running integer (works on max_streams, max_transcodes, max_profiles).
- `append` — extend the array, deduplicating (works on library_ids).

### Attribute / op / value-type matrix

| Attribute | Allowed ops | value must be |
|---|---|---|
| `role` | `set` | `user` or `admin` |
| `library_ids` | `set`, `append` | array of integers |
| `max_streams` | `set`, `add` | integer |
| `max_transcodes` | `set`, `add` | integer |
| `max_profiles` | `set`, `add` | integer |
| `download_allowed` | `set` | boolean (`true` / `false`) |
| `download_transcode_allowed` | `set` | boolean |
| `max_playback_quality` | `set` | string (`""`, `4k`, `1080p`, `720p`, `480p`) |

The form rejects malformed mappings on save with a clear error.

## Admin Service Actions

On the admin's service-detail page for any Continuum-backed service:

- **Continuum status tab** — shows the user's current Continuum state
  (id, email, enabled, role, libraries, stream limit) and a
  **Open in Continuum →** link that opens the Continuum admin user page
  in a new tab. From there you can edit attributes, view sessions, or
  click Continuum's in-UI **Impersonate** button (which is admin-session-bound;
  the WHMCS module cannot drive it via API key).
- **Reconcile from WHMCS** button — re-pushes the product config +
  configurable-options to Continuum. Use after manual edits in Continuum
  or restores from backup.
- **Reset password** button — generates a strong random password, pushes
  to Continuum, writes back to the WHMCS service password, fires WHMCS's
  password-reset email.

## Optional: daily drift detection

Set the server-level config flag `reconcile_daily: yes` to have WHMCS's
`DailyCronJob` walk all active Continuum-backed services and log drifts to
**Utility → Logs → Activity Log**. It does NOT auto-correct; that's still
the admin's job via "Reconcile from WHMCS" per service.

## Troubleshooting

### "Custom field 'continuum_user_id' is not declared on this product"

You skipped Step 4 above. Add the two service custom fields to this product.

### "No Continuum user is linked to this service" on a service that used to work

The customer's Continuum user was likely deleted out-of-band, or both their
email and service username changed simultaneously on the Continuum side
without WHMCS knowing. Either:

1. Recreate the user manually in Continuum with the WHMCS-side email or
   username, then click **Reconcile from WHMCS** on the service detail page.
2. Or correct the custom field `continuum_user_id` on the service directly
   to point at the right Continuum user, then Reconcile.

### "Continuum returned a server error"

Continuum host is responding with a 5xx. Check the Module Log at
**Utility → Logs → Module Log** for the full response body.

### "Username namespace congested — 5 collisions in a row"

This is statistically impossible at any realistic scale (P ≈ 3×10⁻¹⁴ at 1M users).
If you see it, you've probably hit a Continuum-side username-uniqueness bug.
Contact support.

## Customer-chosen usernames

When the per-product flag `allow_user_chosen_username = yes`, the order form
captures `desired_username` (declared as a service custom field) and the
module routes it through:

1. Format check (3-32 chars; lowercase letters, digits, underscore, hyphen).
2. Reserved-name check (`admin`, `root`, etc., plus operator additions).
3. Profanity check against `data/bad_words.default.txt` (or operator's
   `bad_words.txt` next to `continuum.php` if present — replaces, doesn't
   merge with, the default).
4. Uniqueness pre-check via Continuum's user list.
5. Live `createUser` against Continuum — a 409 here surfaces as "already
   taken" without auto-retry. If the customer leaves the field blank,
   the module falls back to the auto-generated 4-letter + 3-digit handle.

## Development

```
composer install
composer test    # PHPUnit
composer lint    # PSR-12 codesniffer
```

Tests use Guzzle's `MockHandler` and a small WHMCS function-stub registry
defined in `tests/bootstrap.php` and `tests/WhmcsFunctionStub.php`. No
real WHMCS, no real Continuum.

## License

Proprietary.
