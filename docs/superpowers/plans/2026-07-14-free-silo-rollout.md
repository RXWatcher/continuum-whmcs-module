# Free Silo Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build, test, and run a guarded WHMCS CLI utility that provisions one free, silent Silo service for each eligible active Emby client.

**Architecture:** Keep reusable rollout decisions in a small pure PHP policy class and place live WHMCS/Capsule/local-API orchestration in a CLI script outside the web root. The utility defaults to dry-run, serializes provisioning, logs only non-PII identifiers, and gates the full batch behind a verified canary state.

**Tech Stack:** PHP 8.3, WHMCS Capsule and local API, PHPUnit 11, JSON Lines, POSIX `flock`.

## Global Constraints

- Select distinct clients with an active service in Emby product group 9.
- Exclude clients with any service in Silo product group 15, regardless of status.
- Use product 122 and server 123; rename product 122 to `S - Service` without changing other product settings.
- New services use billing cycle `Free` with first and recurring amounts of `0.00`.
- Provision immediately and preserve every existing Emby service unchanged.
- Generate no invoice and send no order, invoice, welcome, or module email.
- Use active payment method `stripe_dynamic`, required by `AddOrder`; no charge or
  invoice is created.
- Rename all pre-rollout active product-122 users with
  `ExistingSiloUsernameRenamer` before the canary and block on any mismatch.
- Process a canary first and never retry an ambiguous failure automatically.
- Audit numeric IDs and results only; never log names, email addresses, passwords, or credentials.

---

## File Structure

- Create `lib/Whmcs/FreeSiloRolloutPolicy.php` for pure eligibility, API parameter, validation, and canary-gate rules.
- Create `scripts/free-silo-rollout.php` for locking, WHMCS queries, local API calls, audit/state persistence, and CLI modes.
- Create `tests/Unit/FreeSiloRolloutPolicyTest.php` for policy regression tests without a live WHMCS runtime.
- Modify the approved design to document the explicit `--verify-canary` transition.

### Task 1: Rollout Policy

**Files:**
- Create: `lib/Whmcs/FreeSiloRolloutPolicy.php`
- Test: `tests/Unit/FreeSiloRolloutPolicyTest.php`

**Interfaces:**
- Produces: `eligibleClientIds(array $activeEmbyClientIds, array $clientsWithSilo): array`
- Produces: `addOrderParams(int $clientId): array`
- Produces: `acceptOrderParams(int $orderId): array`
- Produces: `serviceErrors(array $service): array`
- Produces: `assertFullRunAllowed(array $state): void`
- Produces: `parseMode(array $argv): string`

- [ ] **Step 1: Write failing policy tests**

Create tests with these exact central assertions:

~~~php
$policy = new FreeSiloRolloutPolicy(122, 123);

self::assertSame([10, 12], $policy->eligibleClientIds([12, 10, 12, 14], [14]));
self::assertSame([
    'clientid' => 10,
    'paymentmethod' => 'stripe_dynamic',
    'pid' => [122],
    'billingcycle' => ['free'],
    'priceoverride' => ['0.00'],
    'noinvoice' => true,
    'noemail' => true,
], $policy->addOrderParams(10));
self::assertSame([
    'orderid' => 55,
    'serverid' => 123,
    'autosetup' => true,
    'sendemail' => false,
], $policy->acceptOrderParams(55));
self::assertSame([], $policy->serviceErrors([
    'packageid' => 122,
    'server' => 123,
    'domainstatus' => 'Active',
    'billingcycle' => 'Free',
    'firstpaymentamount' => '0.00',
    'amount' => '0.00',
]));
~~~

Also test duplicate removal, all validation errors, all four valid CLI modes, invalid mode rejection, and rejection of a full run without both a positive `canary_service_id` and non-empty `canary_verified_at`.

- [ ] **Step 2: Run the focused test and verify failure**

Run `vendor/bin/phpunit tests/Unit/FreeSiloRolloutPolicyTest.php`.

Expected: failure because `FreeSiloRolloutPolicy` does not exist.

- [ ] **Step 3: Implement the minimal policy**

Implement a final class under `Silo\WhmcsModule\Whmcs`. Sort and deduplicate positive integer client IDs, exclude existing Silo clients with `array_diff`, return the exact local-API arrays above, and report explicit validation errors for wrong product, server, status, billing cycle, first payment, or recurring amount. `parseMode()` accepts only `--dry-run`, `--execute-canary`, `--verify-canary`, and `--execute-all` and defaults to `--dry-run`.

- [ ] **Step 4: Run focused and complete tests**

Run:

~~~bash
vendor/bin/phpunit tests/Unit/FreeSiloRolloutPolicyTest.php
vendor/bin/phpunit
~~~

Expected: both commands exit zero with no failures or errors.

- [ ] **Step 5: Commit the policy**

~~~bash
git add lib/Whmcs/FreeSiloRolloutPolicy.php tests/Unit/FreeSiloRolloutPolicyTest.php
git commit -m "Add guarded free Silo rollout policy"
~~~

### Task 2: CLI Rollout Adapter

**Files:**
- Create: `scripts/free-silo-rollout.php`
- Modify: `docs/superpowers/specs/2026-07-14-free-silo-rollout-design.md`
- Modify test: `tests/Unit/FreeSiloRolloutPolicyTest.php` only if argument behavior needs another case.

**Interfaces:**
- Consumes all Task 1 policy methods.
- Produces modes `--dry-run`, `--execute-canary`, `--verify-canary`, and `--execute-all`.
- Produces private runtime files `/root/silo-rollout-state.json`, `/root/silo-rollout-audit.jsonl`, and `/root/silo-rollout.lock`.

- [ ] **Step 1: Add and run a failing CLI contract test**

Test that an invalid mode fails before WHMCS is loaded and that mode parsing remains case-sensitive. Run the focused test and confirm it fails for the new assertion.

- [ ] **Step 2: Implement CLI-only and locking guards**

The script exits 64 outside CLI, parses the mode before loading WHMCS, opens the lock with mode `c`, and acquires `LOCK_EX | LOCK_NB`. A lock failure exits without touching state.

- [ ] **Step 3: Implement private state and audit persistence**

Create files with mode `0600`. Append one JSON object per event containing timestamp, mode, client ID, order ID, service ID, result, and error only. Write state atomically using a temporary file in `/root` followed by rename.

- [ ] **Step 4: Implement candidate queries and pre-write snapshot**

Load `/opt/whmcs/init.php` and the deployed module autoloader. Query distinct active Emby client IDs through `tblhosting` joined to `tblproducts`, query clients with any Silo-group service, and pass both lists to `eligibleClientIds()`. Before writing, persist product 122's row, its pricing rows, candidate IDs, maximum `tblemails.id`, and timestamp. Do not persist PII.

- [ ] **Step 5: Implement dry-run**

`--dry-run` prints source group 9, target product 122, target server 123, current product name, active-Emby client count, excluded-existing-Silo count, and eligible count. It creates no state/audit file and changes no database row.

- [ ] **Step 6: Implement serial provisioning**

For canary and full modes, recheck eligibility immediately before `localAPI('AddOrder', $policy->addOrderParams($clientId))`. Require `result=success`, exactly one positive service ID from `serviceids`, and a positive order ID. Inspect the service; if pending, call `AcceptOrder` once with `acceptOrderParams()`. Inspect again, append all `serviceErrors()`, and never repeat a module action for an ambiguous result.

`--execute-canary` snapshots product 122, renames only its `name` field to `S - Service`, provisions exactly one eligible client, records its IDs, and exits. `--execute-all` calls `assertFullRunAllowed()` before writes and recomputes eligibility before each client.

- [ ] **Step 7: Implement canary verification**

`--verify-canary` checks the recorded service with `serviceErrors()` and verifies no `tblemails` row above the pre-canary high-water mark belongs to the canary client. After the operator separately checks `postqueue -p`, the command records `canary_verified_at` and `canary_service_id`. It performs no provisioning.

- [ ] **Step 8: Update design and verify**

Add `--verify-canary` to the design's mode list. Run:

~~~bash
php -l scripts/free-silo-rollout.php
php -l lib/Whmcs/FreeSiloRolloutPolicy.php
vendor/bin/phpunit
git diff --check
~~~

Expected: no syntax errors, no test failures, and no whitespace errors.

- [ ] **Step 9: Commit the CLI utility**

~~~bash
git add scripts/free-silo-rollout.php lib/Whmcs/FreeSiloRolloutPolicy.php tests/Unit/FreeSiloRolloutPolicyTest.php docs/superpowers/specs/2026-07-14-free-silo-rollout-design.md
git commit -m "Add free Silo rollout utility"
~~~

### Task 3: Live Dry Run and Canary

**Files:**
- Temporarily deploy tested files under `/root/silo-rollout/` with ownership `root:root` and mode `0600`.
- Create runtime state, audit, and lock files under `/root`.

- [ ] **Step 1: Verify baselines**

Run the full local test suite and PHP lint. On `wave-ninja` capture product 122, candidate count, active Silo count, maximum `tblemails.id`, and `postqueue -p`. Confirm welcome email remains zero and server 123 is active.

- [ ] **Step 2: Install privately and dry-run**

Copy only the tested script, policy, and module autoloader dependency under `/root/silo-rollout/`. Confirm Apache cannot serve the path. Run `php /root/silo-rollout/free-silo-rollout.php --dry-run`.

Expected: current aggregate counts are reported while database rows, email log, mail queue, state, and audit remain unchanged.

- [ ] **Step 3: Execute one canary**

Before provisioning the canary, discover all active product-122 services that
predate the rollout and rename them through `ExistingSiloUsernameRenamer`. Write
old/new usernames only to a separate mode-`0600` sensitive journal. Require every
rename to succeed with `critical_mismatch=false`; otherwise stop.

Run `php /root/silo-rollout/free-silo-rollout.php --execute-canary`.

Expected: product 122 is renamed; exactly one new product-122 service is active, free, assigned to server 123, and present in Silo; no invoice or email exists.

- [ ] **Step 4: Verify the canary gate**

Inspect the WHMCS service, Silo module log, `tblemails`, and `postqueue -p`. Run `--verify-canary` only after all checks pass. Stop on any discrepancy.

### Task 4: Full Rollout and Verification

- [ ] **Step 1: Execute remaining clients**

Run `php /root/silo-rollout/free-silo-rollout.php --execute-all`.

Expected: serial progress records each success or failure and never retries an ambiguous module result.

- [ ] **Step 2: Verify live results**

Compare the pre-run candidate set with product-122 services; check exactly one Silo service per candidate, server 123, Active status, Free cycle, zero amounts, unchanged Emby snapshots, no candidate email-log rows, and no rollout mail in Postfix. Report every failure by numeric client/service ID.

- [ ] **Step 3: Preserve evidence and remove executables**

Move non-PII state and audit evidence to a restricted backup location. Remove the executable rollout copy from `/root/silo-rollout/`, retaining evidence until the Emby-to-Silo migration is complete.

- [ ] **Step 4: Final repository verification**

Run `vendor/bin/phpunit`, `git status --short`, and `git log -3 --oneline`. Expected: tests pass, the worktree is clean, and the plan/policy/utility commits are present.
