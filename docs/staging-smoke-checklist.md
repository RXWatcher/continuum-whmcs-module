# Staging smoke checklist

Run this checklist against a staging WHMCS install and staging Silo server
before publishing a release archive. It verifies the WHMCS contracts that
cannot be proven by unit tests alone.

Record the WHMCS version, Silo version/commit, module commit, tester, and
date at the top of the staging run notes.

## Setup

- Install the module archive into `modules/servers/silo`.
- Configure two Silo servers in WHMCS when testing multi-server re-home:
  server A and server B, both type `silo`, both active.
- Configure at least one Silo-backed product with:
  - `Delete Silo user on termination` ON for delete-path tests.
  - A second product or temporary product setting with it OFF for retention tests.
  - `Re-home returning customers (multi-server)` ON for the re-home scenario.
  - `Allow client-area password reset` ON, then OFF for the toggle scenario.
- Enable WHMCS Module Log and Activity Log visibility for the tester.
- Have a test client account whose Silo sessions/profiles can be created and
  safely reset/deleted.

## 1. Server Connection

Action:
- Open the WHMCS server configuration for the Silo server.
- Use **Test Connection** with valid hostname, port, TLS setting, and API key.
- Repeat once with a deliberately bad API key.

Pass:
- Valid settings return success.
- Bad API key returns an authentication-specific failure.
- Module Log masks the API key.

## 2. Create Account And Linkage

Action:
- Place or accept one order for a Silo-backed product.
- Run CreateAccount/provisioning.
- Inspect the WHMCS service and Silo admin user.

Pass:
- Silo user exists with role `user`.
- `silo_user_id` is populated on the WHMCS service.
- WHMCS service username is populated with the generated or chosen username.
- Product limits, libraries, downloads, playback quality, and default profile
  match the product/configurable options.

## 3. Custom Fields Shape

Action:
- On the provisioned service, inspect custom fields returned by WHMCS.
- Confirm `silo_user_id` and `silo_library_names_cache` exist and can be
  written/read by rerunning **Reconcile from WHMCS**.

Pass:
- Reconcile succeeds.
- `silo_user_id` remains correct.
- No duplicate `desired_username` field is created when a piped or plain
  `desired_username` field already exists.

## 4. Configurable Options Scaffold

Action:
- Click **Scaffold Configurable Options** on a Silo-backed service.
- Open the linked WHMCS configurable option group and product order form.

Pass:
- `Silo Options` group exists.
- Starter options and one `Library N` checkbox per live library are present.
- Pricing rows exist for every currency with `0.00` values.
- Re-running scaffold does not duplicate options or overwrite existing prices.

## 5. Service Credential Writeback

Action:
- Use admin **Reset Password** on the WHMCS service.
- Inspect the WHMCS service password field and Silo login behavior.

Pass:
- Handler returns `success`.
- WHMCS service password is updated.
- New password works against Silo.
- Old Silo sessions are rejected.
- Module Log masks the generated password.

## 6. Client-Area Password Reset Toggle

Action:
- With `Allow client-area password reset` ON, use the client-area
  **Reset password & sign out all devices** action.
- Repeat after setting `Allow client-area password reset` OFF.

Pass:
- ON: action returns a generated password, WHMCS service password is updated,
  and old Silo sessions are rejected.
- OFF: action returns a disabled/support message and does not change the Silo
  password.
- The returned message displays correctly in the active WHMCS client theme.

## 7. Suspend, Unsuspend, And Reconcile Enabled State

Action:
- Suspend the service.
- Run **Reconcile from WHMCS** while the WHMCS service is Suspended.
- Unsuspend the service.

Pass:
- Suspend sets Silo `enabled = false`.
- Reconcile does not re-enable the suspended service.
- Unsuspend sets Silo `enabled = true`.
- Activity/Module logs contain no unexpected errors.

## 8. Retain-On-Terminate Same-Server Reorder

Action:
- Set `Delete Silo user on termination` OFF.
- Terminate the service.
- Place a new order that lands on the same Silo server, or reactivate the same
  service.

Pass:
- Terminate disables the Silo user rather than deleting it.
- Reorder/reactivation re-links the same Silo user ID.
- The Silo user is re-enabled and history/profiles remain intact.

## 9. Delete-On-Terminate

Action:
- Set `Delete Silo user on termination` ON.
- Terminate a test service.

Pass:
- Silo user is deleted.
- `silo_user_id` is cleared or no longer points to a live user.
- `mod_silo_home` pointer for that email is removed.
- Re-running terminate is idempotent/successful when the user is already gone.

## 10. Multi-Server Re-Home

Action:
- On server A, create a user/service with `Delete Silo user on termination`
  OFF.
- Terminate it so the Silo user remains disabled on A.
- Place a brand-new order routed to server B with
  `Re-home returning customers (multi-server)` ON.

Pass:
- WHMCS service is moved from B to A.
- Existing Silo user on A is re-linked and re-enabled.
- No fresh account is created on B.
- `mod_silo_home` contains the customer email, server A ID, and user ID.
- A follow-up hook, such as Suspend, operates against server A.
- If WHMCS ignores `UpdateClientProduct serverid`, Activity Log records the
  direct `tblhosting.server` fallback.

## 11. Client Area Enrichment

Action:
- View the service client area with profiles and active sessions present.
- Temporarily make profiles endpoint fail, then sessions endpoint fail.
- View a suspended service and a service where `getUser` is unavailable.

Pass:
- Active service shows plan, profile usage, library names, last seen, sign-in
  link, and watching-now titles.
- `client_ip`, bitrates, and other session PII are never displayed.
- Profiles failure hides only profile rows.
- Sessions failure hides only active stream rows.
- Suspended or status-unavailable views do not call the server-wide sessions
  endpoint.

## 12. Daily Cron Drift

Action:
- Manually create drift in Silo for one linked service: change one limit,
  library access, playback quality, and enabled state.
- Run the WHMCS daily cron or invoke the DailyCronJob hook in staging.

Pass:
- Activity Log reports drift for each changed attribute.
- `library_ids = null` versus a restricted list is reported as drift.
- The cron does not auto-fix drift; **Reconcile from WHMCS** is still the
  manual correction path.

## 13. Client Email Rename

Action:
- Rename the WHMCS client email.
- Inspect every configured active Silo server and `mod_silo_home`.

Pass:
- Silo user email is updated on the server where the user exists.
- Missing users on other servers are ignored.
- `mod_silo_home` pointer moves to the new lowercase email.
- Activity Log reports successful updates or non-fatal failures.

## 14. Release Archive Sanity

Action:
- Build the archive using the README release command.
- Extract it into a temporary directory.

Pass:
- Archive contains `silo.php`, `hooks.php`, `autoload.php`, `lib/`,
  `templates/`, `data/`, README/docs as desired.
- Archive excludes `.git`, `vendor`, `dist`, local caches, `.env`, and
  `bad_words.txt`.
- `php -l` passes on every PHP file in the extracted archive.

## Rollback

Before testing destructive paths, note the WHMCS service IDs, Silo user IDs,
and Silo server IDs. If staging data must be restored, delete test users from
Silo, remove test WHMCS services/orders, and clear any `mod_silo_home` rows
for the test emails.
