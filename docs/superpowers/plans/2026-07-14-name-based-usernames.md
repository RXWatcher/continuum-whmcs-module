# Name-Based Silo Usernames Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate name-based Silo usernames for new accounts and provide a tested compensating rename service for the two existing product-122 accounts.

**Architecture:** Extend the existing stateless `UsernameGenerator` with normalization and name-based candidate generation while retaining its current random fallback. Keep creation retry orchestration in `CreateAccount`. Put the two-system existing-account rename transaction in a separate service with injected WHMCS callbacks so rollback and mismatch behavior can be tested without a live WHMCS installation.

**Tech Stack:** PHP 8.1+, PHPUnit 11, existing Silo `ClientInterface`, WHMCS local API adapters supplied later by the rollout CLI.

## Global Constraints

- Format is first initial plus up to four normalized surname letters plus three zero-padded random digits.
- Normalize names by trimming, ASCII transliteration when available, lowercasing, and removing all characters outside `a-z`.
- Use the existing random format when either normalized name is unusable.
- Try ten name-based candidates for new accounts, then use the existing random generator and retry behavior.
- Explicit customer-selected usernames bypass generation.
- The two existing product-122 accounts are renamed in Silo and WHMCS before the rollout canary.
- Existing-account rename never falls back to an unrelated random username.
- Rename changes only the username; it does not send email or include password, entitlement, profile, history, or session fields.
- Any unresolved Silo/WHMCS mismatch halts the rename batch and blocks the bulk rollout.

---

### Task 1: Name-Based Candidate Generator

**Files:**
- Modify: `lib/UsernameGenerator.php`
- Modify: `tests/Unit/UsernameGeneratorTest.php`

**Interfaces:**
- Preserve: `UsernameGenerator::generate(): string`
- Produce: `UsernameGenerator::generateFromName(string $firstName, string $lastName, ?callable $randomInt = null): ?string`
- Produce: `UsernameGenerator::normaliseNamePart(string $value): string`

- [ ] **Step 1: Write failing generator tests**

Add data-provider coverage with an injected suffix callback returning `7`:

~~~php
#[DataProvider('nameCases')]
public function testGeneratesNameBasedUsername(
    string $first,
    string $last,
    ?string $expected
): void {
    self::assertSame(
        $expected,
        UsernameGenerator::generateFromName($first, $last, static fn(): int => 7)
    );
}

public static function nameCases(): array
{
    return [
        'standard' => ['Jim', 'Cole', 'jcole007'],
        'surname truncated' => ['Sarah', 'Smith', 'ssmit007'],
        'short surname' => ['Amy', 'Li', 'ali007'],
        'apostrophe' => ['David', "O'Connor", 'docon007'],
        'compound' => ['Emma', 'Van Dijk', 'evand007'],
        'hyphen' => ['Anne-Marie', 'Smith-Jones', 'asmit007'],
        'whitespace and case' => ['  JIM ', ' cOLE  ', 'jcole007'],
        'missing first' => ['', 'Cole', null],
        'missing last' => ['Jim', '', null],
        'non-latin unusable' => ['李', '王', null],
    ];
}
~~~

Also assert suffix bounds by passing callbacks that return `-1` and `1000` and expecting `InvalidArgumentException`. Keep the existing format test for `generate()`.

- [ ] **Step 2: Run the focused test and verify RED**

Run `vendor/bin/phpunit tests/Unit/UsernameGeneratorTest.php`.

Expected: failure because `generateFromName()` does not exist.

- [ ] **Step 3: Implement normalization and candidate generation**

`normaliseNamePart()` trims, attempts `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', ...)` when available, lowercases, and removes `[^a-z]`. If transliteration returns false, continue with the original value so ASCII input still works. `generateFromName()` returns null when either normalized value is empty, takes one first-name letter and four surname letters, obtains a suffix through the injected callback or `random_int(0, 999)`, validates the range, and appends `str_pad((string) $suffix, 3, '0', STR_PAD_LEFT)`.

- [ ] **Step 4: Run focused and full tests**

~~~bash
vendor/bin/phpunit tests/Unit/UsernameGeneratorTest.php
vendor/bin/phpunit
~~~

Expected: both commands exit zero with pristine output.

- [ ] **Step 5: Commit**

~~~bash
git add lib/UsernameGenerator.php tests/Unit/UsernameGeneratorTest.php
git commit -m "Add name-based Silo username generation"
~~~

### Task 2: CreateAccount Retry Integration

**Files:**
- Modify: `lib/Handler/CreateAccount.php`
- Modify: `tests/Handler/CreateAccountTest.php`

**Interfaces:**
- Consumes `UsernameGenerator::generateFromName()` and existing `generate()`.
- Preserves the public `CreateAccount::handle(array $params): string` contract.

- [ ] **Step 1: Write failing creation tests**

Add tests proving:

1. A new user for Jim Cole receives a username matching `/^jcole\d{3}$/`.
2. Missing last name receives the existing `/^[a-z]{4}\d{3}$/` format.
3. Ten name-based duplicate responses are followed by a successful random-format attempt.
4. A desired username is used unchanged and does not invoke generated candidates.
5. A non-duplicate API error still fails immediately.
6. Transient-error email recovery still runs before another candidate is attempted.

Use `FakeClient` response queues or a focused test double to record every create payload. Assert the first ten collision payloads use the correct name stem and the fallback payload uses the old format.

- [ ] **Step 2: Run focused test and verify RED**

Run `vendor/bin/phpunit tests/Handler/CreateAccountTest.php`.

Expected: the new name-format assertion fails because creation still uses the old generator.

- [ ] **Step 3: Implement two-phase retry**

Read `clientsdetails.firstname` and `clientsdetails.lastname`. If `generateFromName()` can produce a candidate, allow ten name-based create attempts. On `duplicate_username`, generate another suffix. For unusable names or after ten collisions, perform the existing five random-format attempts. Preserve duplicate recovery, strict identity resolution, desired-username validation, error wording, and writeback behavior.

Keep the two retry phases inside the existing generated-username branch; do not
alter product configuration or public hooks.

- [ ] **Step 4: Run focused and full tests**

~~~bash
vendor/bin/phpunit tests/Handler/CreateAccountTest.php
vendor/bin/phpunit
~~~

Expected: all tests pass with no warnings.

- [ ] **Step 5: Commit**

~~~bash
git add lib/Handler/CreateAccount.php tests/Handler/CreateAccountTest.php
git commit -m "Use name-based usernames during provisioning"
~~~

### Task 3: Existing Account Rename Service

**Files:**
- Create: `lib/Whmcs/ExistingSiloUsernameRenamer.php`
- Create: `tests/Unit/ExistingSiloUsernameRenamerTest.php`

**Interfaces:**
- Constructor consumes `ClientInterface $client`, a WHMCS writer with signature
  `callable(int $serviceId, string $username): void`, a WHMCS reader with
  signature `callable(int $serviceId): string`, and an optional candidate factory
  with signature `callable(string $firstName, string $lastName, int $attempt): ?string`.
- Produce: `rename(int $serviceId, int $userId, string $oldUsername, string $firstName, string $lastName): array`.
- Result shape: `['success' => bool, 'service_id' => int, 'user_id' => int, 'old_username' => string, 'new_username' => ?string, 'error' => string, 'critical_mismatch' => bool]`.

- [ ] **Step 1: Write failing rename tests**

Use `FakeClient` plus in-memory read/write callbacks to cover:

- Successful available-candidate rename and exact read-back.
- Existing candidate collision followed by a second candidate.
- Ten collisions leave the old username unchanged.
- Missing/unusable names leave the old username unchanged.
- Silo update payload is exactly `['username' => $candidate]`.
- WHMCS write failure restores the old Silo username.
- Failed Silo rollback sets `critical_mismatch=true`.
- Silo read-back mismatch triggers compensation and failure.
- WHMCS read-back mismatch triggers compensation and failure.
- Lookup outage fails closed without changing either system.

Use a deterministic candidate factory that returns a known sequence so tests contain no randomness.

- [ ] **Step 2: Run focused test and verify RED**

Run `vendor/bin/phpunit tests/Unit/ExistingSiloUsernameRenamerTest.php`.

Expected: failure because the renamer class does not exist.

- [ ] **Step 3: Implement guarded rename orchestration**

Validate positive service/user IDs and a non-empty old username. Generate at most ten name-based candidates, skip occupied names unless the occupied record is the same user, and never use the random fallback. Update Silo first using a username-only payload, then invoke the WHMCS writer. Read both systems back and require the candidate in each.

On any failure after the Silo update, compensate both systems: call
`updateUser($userId, ['username' => $oldUsername])`, invoke the WHMCS writer with
the old username, and read both systems back. Set `critical_mismatch` when
compensation cannot establish the old username in both systems. Never catch an
error and report success.

- [ ] **Step 4: Run focused and full tests**

~~~bash
vendor/bin/phpunit tests/Unit/ExistingSiloUsernameRenamerTest.php
vendor/bin/phpunit
php -l lib/Whmcs/ExistingSiloUsernameRenamer.php
git diff --check
~~~

Expected: all tests pass, lint reports no syntax errors, and the diff check is silent.

- [ ] **Step 5: Commit**

~~~bash
git add lib/Whmcs/ExistingSiloUsernameRenamer.php tests/Unit/ExistingSiloUsernameRenamerTest.php
git commit -m "Add guarded existing Silo username renames"
~~~

### Task 4: Rollout Plan Integration

**Files:**
- Modify: `docs/superpowers/plans/2026-07-14-free-silo-rollout.md`
- Modify: `docs/superpowers/specs/2026-07-14-free-silo-rollout-design.md`

**Interfaces:**
- Consumes the generator, `CreateAccount` behavior, and renamer from Tasks 1-3.
- Produces an explicit pre-canary rename phase in the paused rollout plan.

- [ ] **Step 1: Amend the rollout documents**

Require the rollout CLI to identify all active product-122 services present before the bulk run, load each linked Silo account, and call `ExistingSiloUsernameRenamer::rename()` before `--execute-canary`. Store old/new usernames in a separate mode-0600 sensitive rename journal, not the non-PII rollout audit. Refuse the canary while any rename result is unsuccessful or has `critical_mismatch=true`.

State that the observed pre-rollout population is two services, but execution-time discovery is authoritative.

- [ ] **Step 2: Validate documentation consistency**

Run:

~~~bash
rg -n "ExistingSiloUsernameRenamer|rename|critical_mismatch|0600" docs/superpowers
git diff --check
vendor/bin/phpunit
~~~

Expected: the design and both plans agree on rename-before-canary ordering, the diff has no whitespace errors, and tests pass.

- [ ] **Step 3: Commit**

~~~bash
git add docs/superpowers/plans/2026-07-14-free-silo-rollout.md docs/superpowers/specs/2026-07-14-free-silo-rollout-design.md
git commit -m "Integrate username renames into Silo rollout"
~~~

### Task 5: Final Review

- [ ] **Step 1: Run complete verification**

~~~bash
vendor/bin/phpunit
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short
~~~

Expected: all tests pass, every PHP file lints, no whitespace errors exist, and the worktree is clean.

- [ ] **Step 2: Review against the approved design**

Verify every normalization example, fallback rule, collision boundary, desired-username bypass, rename compensation path, sensitive-journal rule, and pre-canary block is represented in code or rollout documentation. Do not deploy or run the live rename in this implementation phase.
