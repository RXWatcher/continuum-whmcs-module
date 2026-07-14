# Name-Based Silo Usernames Design

## Goal

Generate recognizable Silo usernames from each WHMCS client's name while
preserving collision resistance and a safe fallback. Apply the same format to
the two Silo services that existed before the bulk rollout.

## Username Format

When no customer-selected username is present, build the username as:

`first initial` + `first four surname letters` + `three random digits`

Examples:

- Jim Cole -> `jcole427`
- Sarah Smith -> `ssmit083`
- Amy Li -> `ali261`
- David O'Connor -> `docon519`
- Emma Van Dijk -> `evand094`

The numeric suffix is always zero-padded to three digits, including values such
as `007`. The resulting username is at most eight characters long.

## Name Normalization

Normalize first and last names independently before constructing the stem:

1. Trim surrounding whitespace.
2. Transliterate accented Latin characters to ASCII when supported.
3. Convert to lowercase.
4. Remove every character other than `a` through `z`.
5. Take the first remaining first-name character and up to four remaining
   surname characters.

Spaces, apostrophes, and hyphens therefore do not consume surname positions.
For example, `O'Connor` becomes `oconnor` and `Van Dijk` becomes `vandijk`.

If either normalized name is empty, transliteration fails to produce usable
letters, or WHMCS does not provide both names, use the existing random username
format instead.

## Collision Handling

Use `random_int(0, 999)` and zero-pad the result. Check each candidate through
Silo's username lookup before creation or rename.

For a new account, attempt up to ten name-based suffixes. A Silo
`duplicate_username` response is treated as a collision even if the preceding
lookup reported the candidate as available. After ten name-based collisions,
fall back to the existing four-random-letters plus three-random-digits generator
and its existing retry behavior.

For an existing-account rename, attempt up to ten name-based suffixes. A rename
that cannot obtain an available name is reported and leaves the old username in
place; it does not fall back to an unrelated random username.

## New Account Creation

Extend `UsernameGenerator` with a name-based generator while retaining the
current random generator as the fallback. `CreateAccount` supplies
`clientsdetails.firstname` and `clientsdetails.lastname` when it needs to
generate a username.

The behavior applies only when the service has no explicit desired username.
Customer-selected usernames continue through the existing validation and
availability checks unchanged.

All other creation behavior remains unchanged: the module still resolves an
existing Silo account before creating one, uses the WHMCS service password,
creates the configured default profile, applies product entitlements, and writes
the final username and Silo user ID back to the WHMCS service.

## Existing Account Rename

The two active product-122 services that existed before the rollout are included
in a one-time controlled rename. At execution time, re-identify them from WHMCS
rather than relying only on a hard-coded count, and require each service to have
a valid linked Silo user.

For each service:

1. Capture the service ID, Silo user ID, and old username in a restricted
   migration journal. Because it contains usernames, treat it as sensitive and
   store it outside the web root with mode `0600`.
2. Generate and verify an available name-based username from the owning client's
   current first and last names.
3. Update the Silo username without changing password, enabled state, profiles,
   history, or entitlements.
4. Write the same username to the WHMCS service.
5. Read both systems back and require an exact match before recording success.

The two systems cannot participate in one database transaction. If the WHMCS
write fails after the Silo update, immediately restore the old Silo username and
verify the restoration. If either rollback or read-back verification fails, halt
the rename batch and record a critical mismatch for manual repair. Do not
continue to the bulk account rollout while any mismatch exists.

## Bulk Rollout Integration

Run the two existing-account renames before creating the rollout canary. The
canary and all remaining newly provisioned users then use the same generator.
The rollout's existing eligibility, free billing, immediate provisioning,
duplicate prevention, canary gate, and audit rules remain unchanged.

The rename and creation operations send no WHMCS email, Silo email, invoice, or
welcome message. Renaming does not reset passwords or call any session-
termination endpoint.

## Error Handling

- Missing or unusable name on new creation: use the existing random generator.
- Missing or unusable name on existing rename: leave the username unchanged and
  report the service for manual review.
- Candidate already exists: generate another three-digit suffix.
- Silo unavailable during lookup: fail closed; do not create or rename.
- New user created despite an ambiguous network response: retain the existing
  email-based recovery behavior before any retry.
- WHMCS/Silo rename mismatch: compensate back to the old username, verify, halt,
  and report.

## Testing

Add focused tests for:

- Standard, short, compound, apostrophized, hyphenated, accented, mixed-case,
  whitespace-padded, missing, and non-Latin names.
- Three-digit zero padding and fixed stem construction.
- Ten name-based collision retries followed by random fallback for new users.
- Ten collisions leaving an existing username unchanged during rename.
- Customer-selected usernames bypassing generation.
- Successful two-system rename, WHMCS-write compensation, failed compensation,
  and read-back mismatch handling.
- Rename payloads containing only the username, with no password, entitlement,
  profile, history, email, or session fields.

## Out of Scope

- Renaming Silo users outside the two existing product-122 services
- Changing customer-selected usernames
- Changing passwords or sending credentials
- Modifying the free-service eligibility rules
- Starting the bulk rollout before the rename and canary checks pass
