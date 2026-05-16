# WHMCS module refactor — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two WHMCS contract bugs, split the 500-line `Hooks.php` into one class per WHMCS hook, replace ad-hoc static helpers with typed interfaces + value objects, and close the `DailyReconciler` / pagination gaps that today live as `// TODO` comments.

**Architecture:** Handler-per-hook under `lib/Handler/`, `Continuum\ClientInterface` + typed `User` value object, `Identity\Resolver` returning the fetched user, `Whmcs\CustomFieldStore` that resolves field name→numeric ID before writes. `continuum.php` and `hooks.php` keep their public function exports byte-identical.

**Tech Stack:** PHP 8.0+, PSR-4 autoload (`Continuum\WhmcsModule\` → `lib/`), Guzzle 7 HTTP, PHPUnit 9.6, php_codesniffer (PSR-12 + 140-char cap).

**Spec:** [`docs/superpowers/specs/2026-05-13-whmcs-module-refactor-design.md`](../specs/2026-05-13-whmcs-module-refactor-design.md)

---

## Phase 1 — Contract bug fixes (must-fix before first deploy)

### Task 1: Create `Whmcs\CustomFieldStore` (skeleton + tests for name→ID resolution)

`UpdateClientProduct` requires numeric field IDs as keys in `customfields`; the current code uses names and writes nothing. This store resolves the ID via `GetClientsProducts` (which returns `{id, name, value}` per field), memoises per service, then writes via `localAPI`.

**Files:**
- Create: `lib/Whmcs/CustomFieldStore.php`
- Create: `tests/Whmcs/CustomFieldStoreTest.php`
- Modify: `phpcs.xml.dist` is already permissive — no change needed.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Whmcs;

use Continuum\WhmcsModule\Tests\WhmcsFunctionStub;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;
use PHPUnit\Framework\TestCase;

final class CustomFieldStoreTest extends TestCase
{
    protected function setUp(): void
    {
        WhmcsFunctionStub::reset();
    }

    public function testWriteResolvesFieldNameToNumericIdAndSendsBase64Serialize(): void
    {
        $captured = [];
        WhmcsFunctionStub::$localApi = function (string $cmd, array $values) use (&$captured) {
            if ($cmd === 'GetClientsProducts') {
                return ['result' => 'success', 'products' => ['product' => [[
                    'customfields' => ['customfield' => [
                        ['id' => 11, 'name' => 'continuum_user_id', 'value' => ''],
                        ['id' => 12, 'name' => 'continuum_library_names_cache', 'value' => ''],
                    ]],
                ]]]];
            }
            if ($cmd === 'UpdateClientProduct') {
                $captured = $values;
            }
            return ['result' => 'success'];
        };

        (new CustomFieldStore())->write(100, 'continuum_user_id', '42');

        $this->assertSame(100, $captured['serviceid']);
        $decoded = unserialize(base64_decode($captured['customfields']));
        $this->assertSame([11 => '42'], $decoded, 'must serialise with numeric id 11, not field name');
    }

    public function testThrowsWhenFieldNotDeclaredOnService(): void
    {
        WhmcsFunctionStub::$localApi = fn() => ['result' => 'success', 'products' => ['product' => [[
            'customfields' => ['customfield' => []],
        ]]]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Custom field 'continuum_user_id' is not declared");
        (new CustomFieldStore())->write(100, 'continuum_user_id', '42');
    }

    public function testMemoisesFieldIdsAcrossWrites(): void
    {
        $getCalls = 0;
        WhmcsFunctionStub::$localApi = function (string $cmd) use (&$getCalls) {
            if ($cmd === 'GetClientsProducts') {
                $getCalls++;
                return ['result' => 'success', 'products' => ['product' => [[
                    'customfields' => ['customfield' => [
                        ['id' => 11, 'name' => 'continuum_user_id', 'value' => ''],
                        ['id' => 12, 'name' => 'continuum_library_names_cache', 'value' => ''],
                    ]],
                ]]]];
            }
            return ['result' => 'success'];
        };

        $store = new CustomFieldStore();
        $store->write(100, 'continuum_user_id', '42');
        $store->write(100, 'continuum_library_names_cache', '{"a":1}');
        $this->assertSame(1, $getCalls, 'GetClientsProducts should be called once per service');
    }

    public function testReadReturnsCurrentValueByFieldName(): void
    {
        WhmcsFunctionStub::$localApi = fn() => ['result' => 'success', 'products' => ['product' => [[
            'customfields' => ['customfield' => [
                ['id' => 11, 'name' => 'continuum_user_id', 'value' => '42'],
            ]],
        ]]]];

        $this->assertSame('42', (new CustomFieldStore())->read(100, 'continuum_user_id'));
    }

    public function testReadReturnsNullWhenFieldMissing(): void
    {
        WhmcsFunctionStub::$localApi = fn() => ['result' => 'success', 'products' => ['product' => [[
            'customfields' => ['customfield' => []],
        ]]]];

        $this->assertNull((new CustomFieldStore())->read(100, 'continuum_user_id'));
    }

    public function testDeclaredFieldNamesListsAllPresentNames(): void
    {
        WhmcsFunctionStub::$localApi = fn() => ['result' => 'success', 'products' => ['product' => [[
            'customfields' => ['customfield' => [
                ['id' => 11, 'name' => 'continuum_user_id', 'value' => ''],
                ['id' => 12, 'name' => 'continuum_library_names_cache', 'value' => ''],
            ]],
        ]]]];

        $this->assertSame(
            ['continuum_user_id', 'continuum_library_names_cache'],
            (new CustomFieldStore())->declaredFieldNames(100),
        );
    }
}
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `composer test 2>&1 | tail -20`
Expected: 6 new failures (`Class "Continuum\WhmcsModule\Whmcs\CustomFieldStore" not found`).

- [ ] **Step 3: Implement `CustomFieldStore`**

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Whmcs;

/**
 * Reads + writes WHMCS service custom fields.
 *
 * WHMCS's UpdateClientProduct API requires the `customfields` payload to
 * be base64(serialize([<numeric_field_id> => <value>])) — addressing the
 * field by its tblcustomfields.id, NOT by its name. This store resolves
 * name → id via GetClientsProducts (which returns {id, name, value} per
 * field), memoises the map per service, then writes.
 *
 * Without this resolution step, the API returns success but silently
 * does not persist the value.
 */
final class CustomFieldStore
{
    /** @var array<int, array<string, int>>  serviceId => [fieldName => fieldId] */
    private array $idMapCache = [];

    public function write(int $serviceId, string $fieldName, string $value): void
    {
        $id = $this->resolveId($serviceId, $fieldName);
        $resp = localAPI('UpdateClientProduct', [
            'serviceid' => $serviceId,
            'customfields' => base64_encode(serialize([$id => $value])),
        ]);
        if (($resp['result'] ?? '') !== 'success') {
            throw new \RuntimeException('UpdateClientProduct returned: ' . json_encode($resp));
        }
    }

    public function read(int $serviceId, string $fieldName): ?string
    {
        foreach ($this->loadFields($serviceId) as $f) {
            if (($f['name'] ?? null) === $fieldName) {
                return (string)($f['value'] ?? '');
            }
        }
        return null;
    }

    /** @return string[] */
    public function declaredFieldNames(int $serviceId): array
    {
        $out = [];
        foreach ($this->loadFields($serviceId) as $f) {
            if (isset($f['name'])) {
                $out[] = (string)$f['name'];
            }
        }
        return $out;
    }

    private function resolveId(int $serviceId, string $fieldName): int
    {
        if (!isset($this->idMapCache[$serviceId])) {
            $map = [];
            foreach ($this->loadFields($serviceId) as $f) {
                if (isset($f['name'], $f['id'])) {
                    $map[(string)$f['name']] = (int)$f['id'];
                }
            }
            $this->idMapCache[$serviceId] = $map;
        }
        if (!isset($this->idMapCache[$serviceId][$fieldName])) {
            throw new \RuntimeException(
                "Custom field '{$fieldName}' is not declared on service {$serviceId}"
            );
        }
        return $this->idMapCache[$serviceId][$fieldName];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadFields(int $serviceId): array
    {
        $resp = localAPI('GetClientsProducts', ['serviceid' => $serviceId]);
        return $resp['products']['product'][0]['customfields']['customfield'] ?? [];
    }
}
```

- [ ] **Step 4: Run tests, verify they pass**

Run: `composer test 2>&1 | tail -5`
Expected: all green (107 + 6 = 113 tests).

- [ ] **Step 5: Run lint**

Run: `composer lint`
Expected: clean (no output).

- [ ] **Step 6: Commit**

```bash
git add lib/Whmcs/CustomFieldStore.php tests/Whmcs/CustomFieldStoreTest.php
git commit -m "feat(whmcs): CustomFieldStore resolves field name → numeric ID

WHMCS's UpdateClientProduct silently drops customfields entries keyed
by name; the API requires the numeric tblcustomfields.id as key.
Resolves name → id via GetClientsProducts, memoises per service.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Replace `Hooks::writeCustomField` + `probeMissingCustomFields` with `CustomFieldStore`

Wire the new store into the existing handler. Drop the inline name-keyed `writeCustomField` and the standalone probe. `ensureLinkage` and the library-cache write both delegate.

**Files:**
- Modify: `lib/Hooks.php` (remove `writeCustomField`, `probeMissingCustomFields`; route `ensureLinkage` + cache writes + probe through `CustomFieldStore`)
- Modify: `lib/HookContext.php` (construct and expose `CustomFieldStore`)
- Modify: `tests/Hooks/CreateAccountTest.php` (the `base64(serialize([name => value]))` assertion changes to `[id => value]`; update stub `GetClientsProducts` to include `id` per field)
- Modify: `tests/Hooks/ChangePackageTest.php`, `tests/Hooks/ChangePackageInternalTest.php`, `tests/Hooks/AdminButtonsTest.php`, `tests/Hooks/SuspendTest.php` (any test that asserts cache or linkage writes — update stubs to include `id` and update payload assertions)

- [ ] **Step 1: Update test fixtures to include field IDs**

Every `GetClientsProducts` stub returning `customfield` entries gets an `id` key. Find and update:

```bash
grep -rn "'name' => 'continuum_user_id'" tests/
grep -rn "'name' => 'continuum_library_names_cache'" tests/
```

For each match, add `'id' => 11` (for `continuum_user_id`) or `'id' => 12` (for `continuum_library_names_cache`) next to the `name` key. Example:

```php
['id' => 11, 'name' => 'continuum_user_id', 'value' => '42'],
['id' => 12, 'name' => 'continuum_library_names_cache', 'value' => ''],
```

Then update any `base64_encode(serialize([...]))` assertions to use the numeric ID:

```php
// before:
$this->assertSame(base64_encode(serialize(['continuum_user_id' => '42'])), $cfCall['customfields']);
// after:
$this->assertSame(base64_encode(serialize([11 => '42'])), $cfCall['customfields']);
```

For tests that decode the payload to assert key presence (`array_key_exists('continuum_library_names_cache', $decoded)`):

```php
// before:
if (is_array($decoded) && array_key_exists('continuum_library_names_cache', $decoded)) {
    $cacheCleared = true;
}
// after:
if (is_array($decoded) && array_key_exists(12, $decoded)) {
    $cacheCleared = true;
}
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `composer test 2>&1 | tail -10`
Expected: existing tests that asserted the name-keyed payload now fail (CreateAccountTest, ChangePackageTest, ChangePackageInternalTest, AdminButtonsTest, etc.).

- [ ] **Step 3: Update `HookContext` to construct + expose `CustomFieldStore`**

Modify `lib/HookContext.php`:

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;

final class HookContext
{
    public function __construct(
        private Client $client,
        private Identity $identity,
        private AttributeMapper $mapper,
        private CustomFieldStore $customFields,
    ) {
    }

    public static function fromParams(array $params): self
    {
        $cfg = ServerConfig::fromParams($params);
        $client = new Client($cfg);
        return new self($client, new Identity($client), new AttributeMapper(), new CustomFieldStore());
    }

    public function client(): Client { return $this->client; }
    public function identity(): Identity { return $this->identity; }
    public function mapper(): AttributeMapper { return $this->mapper; }
    public function customFields(): CustomFieldStore { return $this->customFields; }
}
```

- [ ] **Step 4: Update `Hooks.php` to route through `CustomFieldStore`**

Replace `Hooks::writeCustomField` body with delegation. Replace `probeMissingCustomFields` to use `CustomFieldStore::declaredFieldNames`.

```php
// REMOVE Hooks::writeCustomField entirely — its callers now use $ctx->customFields()->write(...)

// REPLACE Hooks::probeMissingCustomFields with:
private function probeMissingCustomFields(HookContext $ctx, int $serviceId): array
{
    if ($serviceId === 0) {
        return [];
    }
    try {
        $present = $ctx->customFields()->declaredFieldNames($serviceId);
    } catch (\Throwable $e) {
        return [];
    }
    $missing = [];
    foreach (['continuum_user_id', 'continuum_library_names_cache'] as $required) {
        if (!in_array($required, $present, true)) {
            $missing[] = $required;
        }
    }
    return $missing;
}

// Update callers of writeCustomField:
//   $this->writeCustomField($params, $name, $value)
// becomes:
//   $ctx->customFields()->write((int)$params['serviceid'], $name, $value)
//
// Specifically:
//   - ensureLinkage:    $ctx->customFields()->write($serviceId, 'continuum_user_id', (string)$userId);
//   - resolveLibraryNames cache write
//   - changePackageInternal cache invalidation
//
// Also update probeMissingCustomFields signature to take HookContext;
// and createAccount must construct $ctx before the probe call.
```

In `createAccount`, move `$ctx = $this->context($params)` before the probe call so the probe can use it.

- [ ] **Step 5: Run tests, verify all pass**

Run: `composer test 2>&1 | tail -5`
Expected: all 113 tests green.

- [ ] **Step 6: Lint + commit**

```bash
composer lint
git add lib/HookContext.php lib/Hooks.php tests/Hooks/
git commit -m "refactor(whmcs): route service custom-field writes through CustomFieldStore

Drops Hooks::writeCustomField (name-keyed, silently dropped writes on
real WHMCS) and probeMissingCustomFields' inline GetClientsProducts call.
All writes go via CustomFieldStore::write, which resolves the numeric
tblcustomfields.id before serialising the payload.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Move "Open in Continuum" deep-link into `AdminServicesTabFields` HTML

Custom-button handlers must return strings; arrays with `redirect/newWindow` don't work. Render the link as a regular `<a target="_blank">` inside the admin-tab HTML, drop the button.

**Files:**
- Modify: `continuum.php` (remove `Open in Continuum` from `AdminCustomButtonArray`, remove `continuum_admin_open_in_continuum`)
- Modify: `lib/Hooks.php` (remove `adminOpenInContinuum`; update `adminServicesTabFields` to include the link)
- Modify: `tests/Hooks/AdminButtonsTest.php` (remove the open-in-continuum tests)
- Modify: `tests/Hooks/AdminTabTest.php` (assert the deep-link `<a>` is in the output)

- [ ] **Step 1: Update `AdminTabTest` to assert the deep-link is rendered**

```php
public function testIncludesDeepLinkButton(): void
{
    $client = $this->createMock(Client::class);
    $client->method('getUser')->willReturn([
        'id' => 42, 'email' => 'a@x.com', 'enabled' => true,
        'role' => 'user', 'library_ids' => [], 'max_streams' => 0,
    ]);
    $client->method('baseUrlForDeepLink')->willReturn('https://continuum.test');
    $identity = $this->createMock(Identity::class);
    $identity->method('resolve')->willReturn(42);
    $ctx = new HookContext($client, $identity, new AttributeMapper(),
        $this->createMock(\Continuum\WhmcsModule\Whmcs\CustomFieldStore::class));

    $fields = (new Hooks($ctx))->adminServicesTabFields([
        'serverhostname' => 'x', 'serversecure' => 'on', 'serverpassword' => 'k',
    ]);

    $html = $fields['Continuum status'];
    $this->assertStringContainsString('href="https://continuum.test/admin/users/42"', $html);
    $this->assertStringContainsString('target="_blank"', $html);
    $this->assertStringContainsString('rel="noopener"', $html);
}
```

(Note: the existing `testReturnsContinuumStatusFields` already constructs the same HookContext; update its constructor too to add the `CustomFieldStore` mock.)

- [ ] **Step 2: Run tests, verify the new test fails**

Run: `composer test -- --filter AdminTabTest 2>&1 | tail -10`
Expected: 1 new failure.

- [ ] **Step 3: Update `adminServicesTabFields` to render the deep-link button**

In `lib/Hooks.php`:

```php
public function adminServicesTabFields(array $params): array
{
    try {
        $ctx = $this->context($params);
    } catch (\InvalidArgumentException $e) {
        return ['Continuum status' => 'Configuration error: ' . htmlspecialchars($e->getMessage())];
    }
    $userId = $ctx->identity()->resolve($params);
    if ($userId === null) {
        return ['Continuum status' => 'No Continuum user is linked. Run "Reconcile from WHMCS".'];
    }
    try {
        $user = $ctx->client()->getUser($userId);
    } catch (ContinuumApiException $e) {
        return ['Continuum status' => 'Continuum unreachable: ' . htmlspecialchars($e->getMessage())];
    }
    $this->ensureLinkage($ctx, $params, $userId);

    $deepLink = htmlspecialchars($ctx->client()->baseUrlForDeepLink() . "/admin/users/{$userId}");
    $rows = [
        "<table cellspacing='0' cellpadding='4' style='font-size:13px;'>",
        "<tr><td><strong>User ID</strong></td><td>" . (int)$user['id'] . "</td></tr>",
        "<tr><td><strong>Email</strong></td><td>" . htmlspecialchars((string)$user['email']) . "</td></tr>",
        "<tr><td><strong>Enabled</strong></td><td>"
            . (($user['enabled'] ?? false) ? '&#10003; Yes' : '&#10007; No') . "</td></tr>",
        "<tr><td><strong>Role</strong></td><td>" . htmlspecialchars((string)$user['role']) . "</td></tr>",
        "<tr><td><strong>Libraries</strong></td><td>"
            . htmlspecialchars(implode(', ', $user['library_ids'] ?? [])) . "</td></tr>",
        "<tr><td><strong>Stream limit</strong></td><td>" . (int)($user['max_streams'] ?? 0) . "</td></tr>",
        "</table>",
        "<p style='margin-top:0.5rem;'>"
            . "<a href=\"{$deepLink}\" target=\"_blank\" rel=\"noopener\" class=\"btn btn-default\">"
            . "Open in Continuum &rarr;</a></p>",
    ];
    return ['Continuum status' => implode('', $rows)];
}
```

Remove the `adminOpenInContinuum` method entirely.

- [ ] **Step 4: Update `continuum.php` to drop the button + handler**

```php
function continuum_AdminCustomButtonArray(): array
{
    return [
        'Reconcile from WHMCS' => 'admin_reconcile',
        'Reset Password' => 'admin_reset_password',
    ];
}

// Remove function continuum_admin_open_in_continuum() entirely.
```

- [ ] **Step 5: Update `AdminButtonsTest` to remove `testOpenInContinuumReturnsRedirect` and `testOpenInContinuumReturnsErrorWhenUnlinked`**

Delete those two test methods.

- [ ] **Step 6: Run full suite + lint**

Run: `composer test && composer lint`
Expected: all green, 2 fewer test methods, 1 new test method, net change in count.

- [ ] **Step 7: Commit**

```bash
git add continuum.php lib/Hooks.php tests/Hooks/AdminButtonsTest.php tests/Hooks/AdminTabTest.php
git commit -m "feat(admin): deep-link moved into AdminServicesTabFields HTML

Custom-button handlers must return strings on real WHMCS; the array
{redirect, newWindow} format is undocumented and does nothing. The
'Open in Continuum' link now renders as a plain <a target=\"_blank\">
inside the admin services tab, where HTML is supported.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Phase 2 — Continuum-side abstractions

### Task 4: Extract `Continuum\ClientInterface` from `Client`

Adds an interface so handlers can depend on it and tests can mock it directly. The concrete `Client` becomes `final` again.

**Files:**
- Create: `lib/Continuum/ClientInterface.php`
- Modify: `lib/Client.php` (declare `final` + implements interface; no behaviour change)
- Modify: every caller that did `createMock(Client::class)` to `createMock(ClientInterface::class)` (most handler tests + `IdentityTest` + `ClientEditSyncTest`)
- Modify: `lib/Identity.php`, `lib/HookContext.php`, `lib/Hooks.php`, `lib/ClientEditSync.php` to type-hint `ClientInterface`
- Modify: `IMPLEMENTATION_NOTES.md` (delete the "non-final because of mocks" drift note)

- [ ] **Step 1: Add the interface**

Create `lib/Continuum/ClientInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Continuum;

interface ClientInterface
{
    /** @param array<string, mixed> $payload */
    public function createUser(array $payload): array;

    /** @param array<string, mixed> $payload */
    public function updateUser(int $userId, array $payload): array;

    public function deleteUser(int $userId): void;

    /** @return array<string, mixed> */
    public function getUser(int $userId): array;

    /** @return array<string, mixed>|null */
    public function findUserByEmail(string $email): ?array;

    /** @return array<string, mixed>|null */
    public function findUserByUsername(string $username): ?array;

    /** @return array<int, array<string, mixed>> */
    public function listLibraries(): array;

    public function baseUrlForDeepLink(): string;
}
```

(The `User` value object comes in Task 5 — leaving arrays here keeps this task small.)

- [ ] **Step 2: Make `Client` implement it**

In `lib/Client.php`:

```php
namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Continuum\ClientInterface;
// ...

final class Client implements ClientInterface   // ← add `final` + implements
{
    // body unchanged
}
```

- [ ] **Step 3: Update consumers**

Change type hints in:
- `lib/Identity.php`: `private Client $client` → `private ClientInterface $client`
- `lib/HookContext.php`: same
- `lib/Hooks.php` constructor of any internal methods that pass `Client` (`changePackageInternal` signature uses `HookContext`, fine; check `resolveLibraryNames`)
- `lib/ClientEditSync.php`: `private Client $client` → `private ClientInterface $client`

Then run `grep -rn "createMock(Client::class)" tests/` and change each to `createMock(ClientInterface::class)` (add the `use Continuum\WhmcsModule\Continuum\ClientInterface;` line in each test file).

- [ ] **Step 4: Run tests + lint**

Run: `composer test && composer lint`
Expected: all green.

- [ ] **Step 5: Delete the carve-out note in `IMPLEMENTATION_NOTES.md`**

Open `IMPLEMENTATION_NOTES.md` and remove the bullet:

```
2. **`Client` and `Identity` are not `final`.** PHPUnit's `createMock()`
   cannot mock `final` classes; the test plan calls `$this->createMock(Client::class)`
   directly. Removed `final` to enable mocking. An alternative would be
   introducing interfaces; that's deferred as a follow-up refactor.
```

- [ ] **Step 6: Commit**

```bash
git add lib/Continuum/ClientInterface.php lib/Client.php lib/Identity.php lib/HookContext.php \
        lib/Hooks.php lib/ClientEditSync.php tests/ IMPLEMENTATION_NOTES.md
git commit -m "refactor(continuum): extract ClientInterface, restore final on Client

Tests now createMock the interface instead of a non-final concrete; the
'not final because of mocks' carve-out goes away.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Add `Continuum\User` value object; methods return `User` instead of arrays

Typed value object catches the kind of field-name typo we hit last month (`last_seen_at` vs `last_active_at`). Construction lowercases email. `raw` is the escape hatch for unmodeled fields.

**Files:**
- Create: `lib/Continuum/User.php`
- Create: `tests/Continuum/UserTest.php`
- Modify: `lib/Continuum/ClientInterface.php` (return-type changes: `?User` instead of `?array`)
- Modify: `lib/Client.php` (each method calls `User::fromApi($body)` on the response)
- Modify: every consumer that did `$user['id']` / `$user['email']` to `$user->id` / `$user->email` — `lib/Identity.php`, `lib/Hooks.php`, `lib/ClientEditSync.php`, every test that mocks `findUserByEmail`/`getUser` etc.

- [ ] **Step 1: Write `UserTest`**

```php
<?php
declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Continuum;

use Continuum\WhmcsModule\Continuum\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testFromApiBuildsTypedObject(): void
    {
        $u = User::fromApi([
            'id' => 42, 'email' => 'a@x.com', 'username' => 'abcd123',
            'enabled' => true, 'role' => 'user',
            'library_ids' => [1, 3], 'max_streams' => 6,
            'last_active_at' => '2026-05-12T14:22:00Z',
        ]);
        $this->assertSame(42, $u->id);
        $this->assertSame('a@x.com', $u->email);
        $this->assertSame('abcd123', $u->username);
        $this->assertTrue($u->enabled);
        $this->assertSame('user', $u->role);
        $this->assertSame([1, 3], $u->libraryIds);
        $this->assertSame(6, $u->maxStreams);
        $this->assertSame('2026-05-12T14:22:00Z', $u->lastActiveAt);
    }

    public function testEmailIsLowercasedOnConstruction(): void
    {
        $u = User::fromApi(['id' => 1, 'email' => 'Alice@Example.COM', 'username' => 'x',
            'enabled' => true, 'role' => 'user', 'library_ids' => [], 'max_streams' => 0]);
        $this->assertSame('alice@example.com', $u->email);
    }

    public function testLastActiveAtIsNullableWhenMissing(): void
    {
        $u = User::fromApi(['id' => 1, 'email' => 'a@x', 'username' => 'x',
            'enabled' => true, 'role' => 'user', 'library_ids' => [], 'max_streams' => 0]);
        $this->assertNull($u->lastActiveAt);
    }

    public function testRawCarriesUnmodeledFields(): void
    {
        $u = User::fromApi(['id' => 1, 'email' => 'a@x', 'username' => 'x',
            'enabled' => true, 'role' => 'user', 'library_ids' => [], 'max_streams' => 0,
            'max_playback_quality' => '4k', 'experimental_field' => 'value']);
        $this->assertSame('4k', $u->raw['max_playback_quality']);
        $this->assertSame('value', $u->raw['experimental_field']);
    }
}
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `composer test -- --filter UserTest 2>&1 | tail -5`
Expected: `Class "Continuum\WhmcsModule\Continuum\User" not found`.

- [ ] **Step 3: Implement `User`**

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Continuum;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,        // already lowercased
        public readonly string $username,
        public readonly bool $enabled,
        public readonly string $role,
        /** @var int[] */
        public readonly array $libraryIds,
        public readonly int $maxStreams,
        public readonly ?string $lastActiveAt,
        /** @var array<string, mixed> */
        public readonly array $raw,
    ) {}

    /** @param array<string, mixed> $body */
    public static function fromApi(array $body): self
    {
        return new self(
            id: (int)($body['id'] ?? 0),
            email: strtolower(trim((string)($body['email'] ?? ''))),
            username: (string)($body['username'] ?? ''),
            enabled: (bool)($body['enabled'] ?? false),
            role: (string)($body['role'] ?? 'user'),
            libraryIds: array_map('intval', $body['library_ids'] ?? []),
            maxStreams: (int)($body['max_streams'] ?? 0),
            lastActiveAt: isset($body['last_active_at']) ? (string)$body['last_active_at'] : null,
            raw: $body,
        );
    }
}
```

- [ ] **Step 4: Run UserTest, verify pass**

Run: `composer test -- --filter UserTest 2>&1 | tail -5`
Expected: 4 tests green.

- [ ] **Step 5: Update `ClientInterface` return types**

```php
public function createUser(array $payload): User;
public function updateUser(int $userId, array $payload): User;
public function getUser(int $userId): User;
public function findUserByEmail(string $email): ?User;
public function findUserByUsername(string $username): ?User;
// listLibraries + baseUrlForDeepLink unchanged
// deleteUser unchanged
```

- [ ] **Step 6: Update `Client` to wrap responses with `User::fromApi`**

In `lib/Client.php`:

```php
public function createUser(array $payload): User
{
    return User::fromApi($this->jsonRequest('POST', '/api/v1/admin/users', $payload));
}

public function updateUser(int $userId, array $payload): User
{
    return User::fromApi($this->jsonRequest('PUT', "/api/v1/admin/users/{$userId}", $payload));
}

public function getUser(int $userId): User
{
    return User::fromApi($this->jsonRequest('GET', "/api/v1/admin/users/{$userId}", null));
}

public function findUserByEmail(string $email): ?User
{
    // existing scan logic, then User::fromApi($matched) at the end
}

public function findUserByUsername(string $username): ?User
{
    // same
}
```

- [ ] **Step 7: Update consumers from `$user['id']` to `$user->id`**

In `lib/Hooks.php`, `lib/Identity.php`, `lib/ClientEditSync.php`, and every test that stubs return values from `findUserByEmail`/`findUserByUsername`/`getUser`. Tests must now `willReturn(User::fromApi([...]))` instead of raw arrays.

`grep -rn "->method('findUserByEmail')->willReturn(\[" tests/` — every one of these needs `User::fromApi(...)` wrapping. Same for `findUserByUsername`, `getUser`, `createUser`, `updateUser`.

For `Hooks::clientArea` which reads many fields:
```php
// before:
$vars['stream_limit'] = (int)($user['max_streams'] ?? 0);
$vars['quality'] = $this->humanQuality((string)($user['max_playback_quality'] ?? ''));
// after:
$vars['stream_limit'] = $user->maxStreams;
$vars['quality'] = $this->humanQuality((string)($user->raw['max_playback_quality'] ?? ''));
```

For `Hooks::adminServicesTabFields`:
```php
"<tr><td><strong>User ID</strong></td><td>" . $user->id . "</td></tr>",
"<tr><td><strong>Email</strong></td><td>" . htmlspecialchars($user->email) . "</td></tr>",
"<tr><td><strong>Enabled</strong></td><td>" . ($user->enabled ? '&#10003; Yes' : '&#10007; No') . "</td></tr>",
"<tr><td><strong>Role</strong></td><td>" . htmlspecialchars($user->role) . "</td></tr>",
"<tr><td><strong>Libraries</strong></td><td>" . htmlspecialchars(implode(', ', $user->libraryIds)) . "</td></tr>",
"<tr><td><strong>Stream limit</strong></td><td>" . $user->maxStreams . "</td></tr>",
```

- [ ] **Step 8: Run full suite + lint**

Run: `composer test && composer lint`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add lib/Continuum/ tests/Continuum/ lib/Client.php lib/Identity.php lib/Hooks.php lib/ClientEditSync.php tests/
git commit -m "feat(continuum): typed User value object returned by ClientInterface

Catches field-name typos at the boundary (last_seen_at vs last_active_at).
raw stays available as escape hatch for unmodeled fields. Email is
lowercased on construction.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Phase 3 — Identity extraction

### Task 6: Add `Identity\Params` (pure static extractors)

**Files:**
- Create: `lib/Identity/Params.php`
- Create: `tests/Identity/ParamsTest.php`

- [ ] **Step 1: Write `ParamsTest`**

```php
<?php
declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Identity;

use Continuum\WhmcsModule\Identity\Params;
use PHPUnit\Framework\TestCase;

final class ParamsTest extends TestCase
{
    public function testEmailIsLowercasedAndTrimmed(): void
    {
        $this->assertSame('alice@x.com', Params::email([
            'clientsdetails' => ['email' => '  Alice@X.COM  '],
        ]));
    }

    public function testEmailIsEmptyWhenMissing(): void
    {
        $this->assertSame('', Params::email([]));
        $this->assertSame('', Params::email(['clientsdetails' => []]));
    }

    public function testUsernameIsTrimmedButCasePreserved(): void
    {
        $this->assertSame('Abcd123', Params::username(['username' => '  Abcd123  ']));
    }

    public function testContinuumUserIdParsesDigitOnlyValues(): void
    {
        $this->assertSame(42, Params::continuumUserId(['customfields' => ['continuum_user_id' => '42']]));
        $this->assertNull(Params::continuumUserId(['customfields' => ['continuum_user_id' => '']]));
        $this->assertNull(Params::continuumUserId(['customfields' => ['continuum_user_id' => 'abc']]));
        $this->assertNull(Params::continuumUserId(['customfields' => []]));
    }

    public function testServiceIdReturnsIntegerOrZero(): void
    {
        $this->assertSame(100, Params::serviceId(['serviceid' => 100]));
        $this->assertSame(100, Params::serviceId(['serviceid' => '100']));
        $this->assertSame(0, Params::serviceId([]));
    }

    public function testPasswordReturnsStringOrEmpty(): void
    {
        $this->assertSame('pw', Params::password(['password' => 'pw']));
        $this->assertSame('', Params::password([]));
    }
}
```

- [ ] **Step 2: Verify fails**

Run: `composer test -- --filter ParamsTest 2>&1 | tail -5`
Expected: class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Identity;

/**
 * Pure static extractors over WHMCS hook `$params`. No state, no IO.
 */
final class Params
{
    public static function email(array $params): string
    {
        return strtolower(trim((string)($params['clientsdetails']['email'] ?? '')));
    }

    public static function username(array $params): string
    {
        return trim((string)($params['username'] ?? ''));
    }

    public static function continuumUserId(array $params): ?int
    {
        $cf = $params['customfields'] ?? [];
        if (!is_array($cf) || !isset($cf['continuum_user_id'])) {
            return null;
        }
        $raw = trim((string)$cf['continuum_user_id']);
        return ($raw !== '' && ctype_digit($raw)) ? (int)$raw : null;
    }

    public static function serviceId(array $params): int
    {
        return (int)($params['serviceid'] ?? 0);
    }

    public static function password(array $params): string
    {
        return (string)($params['password'] ?? '');
    }
}
```

- [ ] **Step 4: Verify pass**

Run: `composer test -- --filter ParamsTest 2>&1 | tail -5`
Expected: 6 tests green.

- [ ] **Step 5: Commit**

```bash
git add lib/Identity/Params.php tests/Identity/ParamsTest.php
git commit -m "feat(identity): Params — pure static extractors over WHMCS hook params

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Add `Identity\Resolved` value object + `Identity\Resolver` returning it

`Resolved` carries the userId AND the fetched `User` so handlers skip an extra `getUser`. `source` tells the heal step whether the custom field needs rewriting.

**Files:**
- Create: `lib/Identity/Resolved.php`
- Create: `lib/Identity/Resolver.php`
- Create: `tests/Identity/ResolverTest.php`

- [ ] **Step 1: Write `Resolved` + `ResolverTest`**

`lib/Identity/Resolved.php`:

```php
<?php
declare(strict_types=1);
namespace Continuum\WhmcsModule\Identity;
use Continuum\WhmcsModule\Continuum\User;

final class Resolved
{
    public const SOURCE_ID       = 'id';
    public const SOURCE_EMAIL    = 'email';
    public const SOURCE_USERNAME = 'username';

    public function __construct(
        public readonly int $userId,
        public readonly string $source,   // one of the SOURCE_* constants
        public readonly User $user,
    ) {}
}
```

`tests/Identity/ResolverTest.php`:

```php
<?php
declare(strict_types=1);
namespace Continuum\WhmcsModule\Tests\Identity;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\Continuum\User;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Identity\Resolved;
use Continuum\WhmcsModule\Identity\Resolver;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    private function user(int $id): User
    {
        return User::fromApi(['id' => $id, 'email' => 'a@x.com', 'username' => 'abcd123',
            'enabled' => true, 'role' => 'user', 'library_ids' => [], 'max_streams' => 0]);
    }

    public function testResolvesByIdWhenCustomFieldHits(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('getUser')->with(42)->willReturn($this->user(42));
        $client->expects($this->never())->method('findUserByEmail');
        $client->expects($this->never())->method('findUserByUsername');

        $r = (new Resolver($client))->resolve([
            'customfields' => ['continuum_user_id' => '42'],
            'clientsdetails' => ['email' => 'a@x.com'],
        ]);

        $this->assertInstanceOf(Resolved::class, $r);
        $this->assertSame(42, $r->userId);
        $this->assertSame(Resolved::SOURCE_ID, $r->source);
        $this->assertSame(42, $r->user->id);
    }

    public function testFallsBackToEmailWhenIdIsStale(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getUser')->willThrowException(new ContinuumApiException('not found', 404));
        $client->expects($this->once())->method('findUserByEmail')
            ->with('a@x.com')->willReturn($this->user(88));

        $r = (new Resolver($client))->resolve([
            'customfields' => ['continuum_user_id' => '42'],
            'clientsdetails' => ['email' => 'a@x.com'],
        ]);

        $this->assertSame(88, $r->userId);
        $this->assertSame(Resolved::SOURCE_EMAIL, $r->source);
    }

    public function testFallsBackToUsernameWhenIdAndEmailMiss(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('findUserByEmail')->willReturn(null);
        $client->expects($this->once())->method('findUserByUsername')
            ->with('abcd123')->willReturn($this->user(77));

        $r = (new Resolver($client))->resolve([
            'customfields' => [],
            'clientsdetails' => ['email' => 'a@x.com'],
            'username' => 'abcd123',
        ]);

        $this->assertSame(77, $r->userId);
        $this->assertSame(Resolved::SOURCE_USERNAME, $r->source);
    }

    public function testReturnsNullWhenAllSignalsMiss(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('findUserByEmail')->willReturn(null);
        $client->method('findUserByUsername')->willReturn(null);

        $this->assertNull((new Resolver($client))->resolve([
            'clientsdetails' => ['email' => 'a@x.com'],
            'username' => 'abcd123',
        ]));
    }
}
```

- [ ] **Step 2: Verify fails**

Run: `composer test -- --filter ResolverTest 2>&1 | tail -5`
Expected: 4 failures (class not found).

- [ ] **Step 3: Implement `Resolver`**

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Identity;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\ContinuumApiException;

final class Resolver
{
    public function __construct(private ClientInterface $client)
    {
    }

    public function resolve(array $params): ?Resolved
    {
        $id = Params::continuumUserId($params);
        if ($id !== null) {
            try {
                $user = $this->client->getUser($id);
                return new Resolved($user->id, Resolved::SOURCE_ID, $user);
            } catch (ContinuumApiException $e) {
                // stale id — fall through
            }
        }

        $email = Params::email($params);
        if ($email !== '') {
            $user = $this->client->findUserByEmail($email);
            if ($user !== null) {
                return new Resolved($user->id, Resolved::SOURCE_EMAIL, $user);
            }
        }

        $username = Params::username($params);
        if ($username !== '') {
            $user = $this->client->findUserByUsername($username);
            if ($user !== null) {
                return new Resolved($user->id, Resolved::SOURCE_USERNAME, $user);
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Verify ResolverTest passes**

Run: `composer test -- --filter ResolverTest 2>&1 | tail -5`
Expected: 4 tests green.

- [ ] **Step 5: Commit**

```bash
git add lib/Identity/Resolved.php lib/Identity/Resolver.php tests/Identity/ResolverTest.php
git commit -m "feat(identity): Resolver returns Resolved{userId, source, user}

Three-tier resolve (id → email → username) now returns the fetched
User alongside the id, so handlers skip a redundant getUser round trip.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Add `Identity\Sync::align()` and replace scattered `syncFields`/`ensureLinkage`

One verb does what handlers used to coordinate by hand: push WHMCS email/username to Continuum if it drifted, write the discovered userId back to the custom field if needed.

**Files:**
- Create: `lib/Identity/Sync.php`
- Create: `tests/Identity/SyncTest.php`
- Modify: `lib/HookContext.php` (construct + expose `Resolver` and `Sync` alongside existing collaborators)
- Modify: `lib/Hooks.php` (replace `$this->ensureLinkage(...)` and `$this->syncFields(...)` calls with `$ctx->sync()->align($resolved, $params)`; the `updateUser` calls drop the `syncFields` merge — `align` handles it after the update)

- [ ] **Step 1: Write `SyncTest`**

```php
<?php
declare(strict_types=1);
namespace Continuum\WhmcsModule\Tests\Identity;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\Continuum\User;
use Continuum\WhmcsModule\Identity\Resolved;
use Continuum\WhmcsModule\Identity\Sync;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;
use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase
{
    private function user(string $email, string $username): User
    {
        return User::fromApi(['id' => 42, 'email' => $email, 'username' => $username,
            'enabled' => true, 'role' => 'user', 'library_ids' => [], 'max_streams' => 0]);
    }

    public function testWritesLinkageWhenSourceIsEmail(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $cfStore = $this->createMock(CustomFieldStore::class);
        $cfStore->expects($this->once())->method('write')->with(100, 'continuum_user_id', '42');
        $resolved = new Resolved(42, Resolved::SOURCE_EMAIL, $this->user('a@x.com', 'abcd123'));

        (new Sync($client, $cfStore))->align($resolved, [
            'serviceid' => 100,
            'customfields' => ['continuum_user_id' => ''],
            'clientsdetails' => ['email' => 'a@x.com'],
            'username' => 'abcd123',
        ]);
    }

    public function testSkipsLinkageWriteWhenSourceIsId(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $cfStore = $this->createMock(CustomFieldStore::class);
        $cfStore->expects($this->never())->method('write');
        $resolved = new Resolved(42, Resolved::SOURCE_ID, $this->user('a@x.com', 'abcd123'));

        (new Sync($client, $cfStore))->align($resolved, [
            'serviceid' => 100,
            'customfields' => ['continuum_user_id' => '42'],
            'clientsdetails' => ['email' => 'a@x.com'],
            'username' => 'abcd123',
        ]);
    }

    public function testPushesEmailWhenWhmcsEmailDiffersFromContinuum(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('updateUser')->with(42, ['email' => 'new@x.com']);
        $cfStore = $this->createMock(CustomFieldStore::class);
        $resolved = new Resolved(42, Resolved::SOURCE_USERNAME, $this->user('old@x.com', 'abcd123'));

        (new Sync($client, $cfStore))->align($resolved, [
            'serviceid' => 100,
            'customfields' => ['continuum_user_id' => '42'],
            'clientsdetails' => ['email' => 'new@x.com'],
            'username' => 'abcd123',
        ]);
    }

    public function testPushesUsernameWhenWhmcsUsernameDiffersFromContinuum(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('updateUser')->with(42, ['username' => 'wxyz999']);
        $cfStore = $this->createMock(CustomFieldStore::class);
        $resolved = new Resolved(42, Resolved::SOURCE_EMAIL, $this->user('a@x.com', 'old_user'));

        (new Sync($client, $cfStore))->align($resolved, [
            'serviceid' => 100,
            'customfields' => ['continuum_user_id' => '42'],
            'clientsdetails' => ['email' => 'a@x.com'],
            'username' => 'wxyz999',
        ]);
    }

    public function testNoopWhenEverythingAligned(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('updateUser');
        $cfStore = $this->createMock(CustomFieldStore::class);
        $cfStore->expects($this->never())->method('write');
        $resolved = new Resolved(42, Resolved::SOURCE_ID, $this->user('a@x.com', 'abcd123'));

        (new Sync($client, $cfStore))->align($resolved, [
            'serviceid' => 100,
            'customfields' => ['continuum_user_id' => '42'],
            'clientsdetails' => ['email' => 'a@x.com'],
            'username' => 'abcd123',
        ]);
    }
}
```

- [ ] **Step 2: Verify fails**

Run: `composer test -- --filter SyncTest 2>&1 | tail -5`
Expected: 5 failures.

- [ ] **Step 3: Implement `Sync`**

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Identity;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;

/**
 * Push WHMCS-side identity facts to Continuum and heal the WHMCS-side
 * custom-field linkage if it drifted. Called after a successful resolve.
 *
 * - If resolve hit via email or username, the continuum_user_id custom
 *   field is wrong or empty — rewrite it.
 * - If the WHMCS client email differs from what Continuum has, push it.
 * - If the WHMCS service username differs, push it.
 * - No-op when everything already aligns. Idempotent.
 */
final class Sync
{
    public function __construct(
        private ClientInterface $client,
        private CustomFieldStore $customFields,
    ) {}

    public function align(Resolved $resolved, array $params): void
    {
        $serviceId = Params::serviceId($params);

        if ($resolved->source !== Resolved::SOURCE_ID && $serviceId !== 0) {
            try {
                $this->customFields->write($serviceId, 'continuum_user_id', (string)$resolved->userId);
            } catch (\Throwable $e) {
                if (function_exists('logActivity')) {
                    logActivity(
                        "continuum: failed to heal continuum_user_id={$resolved->userId} for "
                        . "service {$serviceId}: " . $e->getMessage()
                    );
                }
            }
        }

        $patch = [];
        $email = Params::email($params);
        if ($email !== '' && $email !== $resolved->user->email) {
            $patch['email'] = $email;
        }
        $username = Params::username($params);
        if ($username !== '' && $username !== $resolved->user->username) {
            $patch['username'] = $username;
        }
        if ($patch !== []) {
            try {
                $this->client->updateUser($resolved->userId, $patch);
            } catch (ContinuumApiException $e) {
                if (function_exists('logActivity')) {
                    logActivity(
                        "continuum: failed to push synced fields to user {$resolved->userId}: "
                        . $e->getMessage()
                    );
                }
            }
        }
    }
}
```

- [ ] **Step 4: Verify SyncTest passes**

Run: `composer test -- --filter SyncTest 2>&1 | tail -5`
Expected: 5 green.

- [ ] **Step 5: Wire Resolver + Sync into `HookContext`**

```php
namespace Continuum\WhmcsModule;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\Identity\Resolver;
use Continuum\WhmcsModule\Identity\Sync;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;

final class HookContext
{
    public function __construct(
        private ClientInterface $client,
        private Resolver $resolver,
        private Sync $sync,
        private AttributeMapper $mapper,
        private CustomFieldStore $customFields,
    ) {}

    public static function fromParams(array $params): self
    {
        $cfg = ServerConfig::fromParams($params);
        $client = new Client($cfg);
        $cfStore = new CustomFieldStore();
        return new self(
            $client,
            new Resolver($client),
            new Sync($client, $cfStore),
            new AttributeMapper(),
            $cfStore,
        );
    }

    public function client(): ClientInterface { return $this->client; }
    public function resolver(): Resolver { return $this->resolver; }
    public function sync(): Sync { return $this->sync; }
    public function mapper(): AttributeMapper { return $this->mapper; }
    public function customFields(): CustomFieldStore { return $this->customFields; }
}
```

Drop the `identity()` method — it's gone. All call sites in `Hooks.php` change.

- [ ] **Step 6: Rewrite `Hooks.php` callers to use `Resolver`/`Sync`**

For each handler method in `Hooks.php`:

```php
// Pattern: replace
//   $userId = $ctx->identity()->resolve($params);
//   ... updateUser($userId, array_merge($attrs, $this->syncFields($params)));
//   $this->ensureLinkage($ctx, $params, $userId);
// with:
//   $resolved = $ctx->resolver()->resolve($params);
//   if ($resolved === null) return 'No Continuum user is linked...';
//   ... $ctx->client()->updateUser($resolved->userId, $attrs);
//   $ctx->sync()->align($resolved, $params);
```

Delete `Hooks::syncFields()` and `Hooks::ensureLinkage()` entirely.

For `Hooks::adminServicesTabFields` and `Hooks::clientArea`, the `getUser` round trip goes away — use `$resolved->user` directly.

- [ ] **Step 7: Update existing handler tests for the new context shape**

Every `new HookContext(...)` constructor call in tests needs the new arg list. `$identity = $this->createMock(Identity::class)` becomes `$resolver = $this->createMock(Resolver::class)` returning `Resolved`. Existing `findUserByEmail` mocks may need to return a `User` (already updated in Task 5).

Where tests mocked `Identity::resolve` returning an `int|null`:
```php
$identity->method('resolve')->willReturn(42);
// becomes:
$resolver->method('resolve')->willReturn(new Resolved(42, Resolved::SOURCE_ID, $someUserObject));
```

Where tests asserted `updateUser` with `syncFields` merged in: drop the email/username keys from the assertion — they no longer come through `updateUser` from `syncFields`, only from `Sync::align`'s separate `updateUser` call.

- [ ] **Step 8: Delete `lib/Identity.php`**

The old `Identity` class is replaced. Make sure nothing imports it.

```bash
grep -rn "use Continuum\\\\WhmcsModule\\\\Identity;" lib/ tests/
```

Should return nothing after step 7. Then:

```bash
rm lib/Identity.php tests/IdentityTest.php
```

- [ ] **Step 9: Run full suite + lint**

Run: `composer test && composer lint`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add lib/Identity/ tests/Identity/ lib/HookContext.php lib/Hooks.php tests/
git rm lib/Identity.php tests/IdentityTest.php
git commit -m "refactor(identity): split into Params/Resolver/Sync; one verb replaces syncFields+ensureLinkage

Identity\Resolver returns Resolved{userId, source, user} so handlers
skip a redundant getUser. Identity\Sync::align is the single push-back
step. Old Identity class deleted.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Phase 4 — Handler split (one class per WHMCS hook)

The next 8 tasks each extract one handler from `Hooks.php`. They follow the same shape: create the handler class, write its tests, update `continuum.php` (or `Hooks.php`) to delegate, remove the corresponding method from `Hooks.php`. After all 8 are extracted, `Hooks.php` itself disappears.

### Task 9: Extract `Handler\CreateAccount`

**Files:**
- Create: `lib/Handler/CreateAccount.php`
- Create: `lib/Handler/HandlerBase.php` (small trait or abstract with `humanError` + `defaultProfileName` shared helpers; if it's only used by one or two handlers, fold into the handler instead — YAGNI)
- Create: `lib/Username/UsernameResolver.php` (extract the customer-chosen-vs-generated branching from `Hooks::resolveUsername`)
- Create: `tests/Handler/CreateAccountTest.php` (mostly moved from `tests/Hooks/CreateAccountTest.php` with new constructor wiring)
- Modify: `continuum.php` — `continuum_CreateAccount` constructs `Handler\CreateAccount` directly
- Modify: `lib/Hooks.php` — remove `createAccount` method
- Delete: `tests/Hooks/CreateAccountTest.php` (replaced)

- [ ] **Step 1: Extract `Username\UsernameResolver`**

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Username;

use Continuum\WhmcsModule\Config\ProductConfig;
use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\Username\BadWordList;
use Continuum\WhmcsModule\Username\Validator;

/**
 * Customer-chosen vs auto-generated username resolution. Returns the
 * username to use, or throws InvalidArgumentException for user-chosen
 * values that fail validation / uniqueness pre-check. For auto-generate,
 * returns null and the caller runs its own retry loop.
 */
final class UsernameResolver
{
    public function __construct(private ClientInterface $client)
    {
    }

    /**
     * @return string|null  string = customer-chosen, ready to use; null = caller must auto-generate
     * @throws \InvalidArgumentException if customer-chosen fails validation or uniqueness
     */
    public function resolve(array $params, ProductConfig $pc): ?string
    {
        if (!$pc->allowUserChosenUsername()) {
            return null;
        }
        $desired = trim((string)($params['customfields']['desired_username'] ?? ''));
        if ($desired === '') {
            return null;
        }

        $validator = new Validator(BadWordList::resolve(__DIR__ . '/../..'));
        if ($err = $validator->validate($desired)) {
            throw new \InvalidArgumentException($err);
        }
        if ($this->client->findUserByUsername($desired) !== null) {
            throw new \InvalidArgumentException("Username '{$desired}' is already taken. Choose another.");
        }
        return $desired;
    }
}
```

Move `lib/UsernameValidator.php` → `lib/Username/Validator.php`, `lib/UsernameGenerator.php` → `lib/Username/Generator.php`, `lib/BadWordList.php` → `lib/Username/BadWordList.php`. Update namespaces.

Update existing tests `tests/UsernameValidatorTest.php` → `tests/Username/ValidatorTest.php`, etc., with namespace updates.

- [ ] **Step 2: Write `CreateAccountTest`**

Take the existing `tests/Hooks/CreateAccountTest.php` and adapt:
- Constructor: `new CreateAccount($client, $resolver, $sync, $mapper, $cfStore, $serviceWriter, $usernameResolver)` instead of `new Hooks($ctx)`.
- Mock `Resolver::resolve` returning `Resolved|null` (was `Identity::resolve` returning `int|null`).
- Drop assertions about `syncFields` merged into `updateUser` (those are `Sync::align`'s job now).
- Drop the orphan-rollback tests (already removed).

(The full test file is ~250 lines — too long to inline here. Reuse the structure of the current `CreateAccountTest.php`; adjust constructor + assertions.)

- [ ] **Step 3: Implement `Handler\CreateAccount`**

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\AttributeMapper;
use Continuum\WhmcsModule\Config\ConfigurableOptionsRuleSet;
use Continuum\WhmcsModule\Config\ProductConfig;
use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Identity\Params;
use Continuum\WhmcsModule\Identity\Resolver;
use Continuum\WhmcsModule\Identity\Sync;
use Continuum\WhmcsModule\Username\Generator;
use Continuum\WhmcsModule\Username\UsernameResolver;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;
use Continuum\WhmcsModule\Whmcs\ServiceWriter;

final class CreateAccount
{
    public function __construct(
        private ClientInterface $client,
        private Resolver $resolver,
        private Sync $sync,
        private AttributeMapper $mapper,
        private CustomFieldStore $customFields,
        private ServiceWriter $service,
        private UsernameResolver $usernameResolver,
    ) {}

    public function handle(array $params): string
    {
        $serviceId = Params::serviceId($params);
        if ($missing = $this->probeMissing($serviceId)) {
            return "Custom field '" . implode("', '", $missing)
                . "' is not declared on this product. See README §Setup.";
        }

        try {
            $pc = ProductConfig::fromParams($params);
            $rs = ConfigurableOptionsRuleSet::fromJson($pc->configurableOptionsMapJson());
            $attrs = $this->mapper->apply($pc, $rs, $this->normaliseOptions($params['configoptions'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return 'Configuration error: ' . $e->getMessage();
        }

        if ($resolved = $this->resolver->resolve($params)) {
            try {
                $this->client->updateUser($resolved->userId, $attrs);
            } catch (ContinuumApiException $e) {
                return $this->humanError($e);
            }
            $this->sync->align($resolved, $params);
            return 'success';
        }

        $email = Params::email($params);
        if ($email === '') {
            return 'Client email is required';
        }
        $defaultProfileName = (string)($params['clientsdetails']['firstname'] ?? '');
        if ($defaultProfileName === '') {
            $defaultProfileName = explode('@', $email)[0];
        }

        try {
            $chosen = $this->usernameResolver->resolve($params, $pc);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $createPayload = function (string $username) use ($attrs, $email, $params, $pc, $defaultProfileName) {
            return array_merge($attrs, [
                'email' => $email,
                'username' => $username,
                'password' => Params::password($params),
                'create_default_profile' => $pc->createDefaultProfile(),
                'default_profile_name' => $defaultProfileName,
            ]);
        };

        if ($chosen !== null) {
            try {
                $user = $this->client->createUser($createPayload($chosen));
            } catch (ContinuumApiException $e) {
                if ($this->isDuplicateUsername($e)) {
                    return "Username '{$chosen}' is already taken. Choose another.";
                }
                return $this->humanError($e);
            }
            $username = $chosen;
        } else {
            $user = null;
            $username = '';
            for ($i = 0; $i < 5; $i++) {
                $username = Generator::generate();
                try {
                    $user = $this->client->createUser($createPayload($username));
                    break;
                } catch (ContinuumApiException $e) {
                    if ($this->isDuplicateUsername($e)) {
                        continue;
                    }
                    return $this->humanError($e);
                }
            }
            if ($user === null) {
                return 'Username namespace congested — 5 collisions in a row.'
                    . ' Retry the order, or contact support if this persists.';
            }
        }

        if ($user->id === 0) {
            return 'Continuum did not return a user ID; cannot persist linkage';
        }

        try {
            $this->customFields->write($serviceId, 'continuum_user_id', (string)$user->id);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity("continuum: failed to persist continuum_user_id={$user->id} "
                    . "for service {$serviceId} — heal via email/username on next hook: " . $e->getMessage());
            }
        }
        $this->service->writeUsername($serviceId, $username);
        return 'success';
    }

    /** @return string[] */
    private function probeMissing(int $serviceId): array
    {
        if ($serviceId === 0) {
            return [];
        }
        try {
            $present = $this->customFields->declaredFieldNames($serviceId);
        } catch (\Throwable $e) {
            return [];
        }
        $missing = [];
        foreach (['continuum_user_id', 'continuum_library_names_cache'] as $required) {
            if (!in_array($required, $present, true)) {
                $missing[] = $required;
            }
        }
        return $missing;
    }

    /** @return array<int, array{name: string, value: string}> */
    private function normaliseOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name => $value) {
            $out[] = ['name' => (string)$name, 'value' => (string)$value];
        }
        return $out;
    }

    private function isDuplicateUsername(ContinuumApiException $e): bool
    {
        return $e->httpStatus() === 409 && (($e->body() ?? [])['error'] ?? '') === 'duplicate_username';
    }

    private function humanError(ContinuumApiException $e): string
    {
        return $e->httpStatus() >= 500
            ? 'Continuum returned a server error. Check Module Log for details.'
            : 'Continuum: ' . $e->getMessage();
    }
}
```

Also create `lib/Whmcs/ServiceWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Whmcs;

final class ServiceWriter
{
    public function writeUsername(int $serviceId, string $username): void
    {
        try {
            localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'username' => $username]);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity("continuum: failed to write back username to service {$serviceId}: " . $e->getMessage());
            }
        }
    }

    public function writeServerPassword(int $serviceId, string $password): void
    {
        $resp = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'serverpassword' => $password]);
        if (($resp['result'] ?? '') !== 'success') {
            throw new \RuntimeException('UpdateClientProduct serverpassword failed: ' . json_encode($resp));
        }
    }
}
```

- [ ] **Step 4: Update `continuum.php` to delegate to the new handler**

```php
function continuum_CreateAccount(array $params): string
{
    $cfg = \Continuum\WhmcsModule\Config\ServerConfig::fromParams($params);
    $client = new \Continuum\WhmcsModule\Client($cfg);
    $cf = new \Continuum\WhmcsModule\Whmcs\CustomFieldStore();
    return (new \Continuum\WhmcsModule\Handler\CreateAccount(
        $client,
        new \Continuum\WhmcsModule\Identity\Resolver($client),
        new \Continuum\WhmcsModule\Identity\Sync($client, $cf),
        new \Continuum\WhmcsModule\AttributeMapper(),
        $cf,
        new \Continuum\WhmcsModule\Whmcs\ServiceWriter(),
        new \Continuum\WhmcsModule\Username\UsernameResolver($client),
    ))->handle($params);
}
```

(Yes that's verbose. Task 17 introduces a factory function. For now: explicit.)

- [ ] **Step 5: Remove `Hooks::createAccount`**

In `lib/Hooks.php`, delete the `createAccount` method.

- [ ] **Step 6: Run tests + lint**

Run: `composer test && composer lint`
Expected: all green. CreateAccount tests pass, no regressions.

- [ ] **Step 7: Commit**

```bash
git add lib/Handler/CreateAccount.php lib/Username/ lib/Whmcs/ServiceWriter.php \
        tests/Handler/CreateAccountTest.php tests/Username/ continuum.php lib/Hooks.php
git rm tests/Hooks/CreateAccountTest.php tests/UsernameValidatorTest.php tests/BadWordListTest.php tests/UsernameGeneratorTest.php
git commit -m "refactor(handlers): extract Handler\\CreateAccount; Username/* gets its own namespace

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Extract `Handler\SetEnabled` (covers Suspend/Unsuspend/Terminate)

Same pattern. `Hooks::setEnabled` becomes `Handler\SetEnabled::handle($params, bool $enabled)`. Three entry points in `continuum.php` delegate to it.

**Files:**
- Create: `lib/Handler/SetEnabled.php` (~40 lines)
- Create: `tests/Handler/SetEnabledTest.php` (port from `tests/Hooks/SuspendTest.php`)
- Modify: `continuum.php` (3 entry points: Suspend/Unsuspend/Terminate)
- Modify: `lib/Hooks.php` (remove `suspendAccount`, `unsuspendAccount`, `terminateAccount`, `setEnabled`)
- Delete: `tests/Hooks/SuspendTest.php`

- [ ] **Step 1: Write test (port from SuspendTest, adjust constructor)**

Pattern (one representative case shown):

```php
public function testSuspendCallsUpdateUserWithEnabledFalse(): void
{
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())->method('updateUser')->with(42, ['enabled' => false]);
    $resolver = $this->createMock(Resolver::class);
    $resolver->method('resolve')->willReturn(new Resolved(42, Resolved::SOURCE_ID,
        User::fromApi(['id' => 42, 'email' => 'a@x', 'username' => 'x', 'enabled' => true,
                       'role' => 'user', 'library_ids' => [], 'max_streams' => 0])));
    $sync = $this->createMock(Sync::class);
    $sync->expects($this->once())->method('align');

    (new SetEnabled($client, $resolver, $sync))->handle([
        'serverhostname' => 'x', 'serversecure' => 'on', 'serverpassword' => 'k',
    ], false);
}
```

- [ ] **Step 2: Verify fails (class not found).**

- [ ] **Step 3: Implement `Handler\SetEnabled`**

```php
<?php
declare(strict_types=1);
namespace Continuum\WhmcsModule\Handler;

use Continuum\WhmcsModule\Continuum\ClientInterface;
use Continuum\WhmcsModule\ContinuumApiException;
use Continuum\WhmcsModule\Identity\Resolver;
use Continuum\WhmcsModule\Identity\Sync;

final class SetEnabled
{
    public function __construct(
        private ClientInterface $client,
        private Resolver $resolver,
        private Sync $sync,
    ) {}

    public function handle(array $params, bool $enabled): string
    {
        $resolved = $this->resolver->resolve($params);
        if ($resolved === null) {
            return 'No Continuum user is linked to this service. Run "Reconcile from WHMCS" first.';
        }
        try {
            $this->client->updateUser($resolved->userId, ['enabled' => $enabled]);
        } catch (ContinuumApiException $e) {
            return $e->httpStatus() >= 500
                ? 'Continuum returned a server error. Check Module Log for details.'
                : 'Continuum: ' . $e->getMessage();
        }
        $this->sync->align($resolved, $params);
        return 'success';
    }
}
```

- [ ] **Step 4: Update `continuum.php`**

```php
function continuum_SuspendAccount(array $params): string  { return setEnabledHandler($params, false); }
function continuum_UnsuspendAccount(array $params): string { return setEnabledHandler($params, true); }
function continuum_TerminateAccount(array $params): string { return setEnabledHandler($params, false); }

function setEnabledHandler(array $params, bool $enabled): string
{
    $cfg = \Continuum\WhmcsModule\Config\ServerConfig::fromParams($params);
    $client = new \Continuum\WhmcsModule\Client($cfg);
    return (new \Continuum\WhmcsModule\Handler\SetEnabled(
        $client,
        new \Continuum\WhmcsModule\Identity\Resolver($client),
        new \Continuum\WhmcsModule\Identity\Sync($client, new \Continuum\WhmcsModule\Whmcs\CustomFieldStore()),
    ))->handle($params, $enabled);
}
```

- [ ] **Step 5: Remove from `Hooks.php`, run tests + lint, commit.**

```bash
git add lib/Handler/SetEnabled.php tests/Handler/SetEnabledTest.php continuum.php lib/Hooks.php
git rm tests/Hooks/SuspendTest.php
git commit -m "refactor(handlers): extract Handler\\SetEnabled (Suspend/Unsuspend/Terminate)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: Extract `Handler\ChangePassword`

Same shape, smaller. `Hooks::changePassword` → `Handler\ChangePassword::handle($params)`.

**Files:**
- Create: `lib/Handler/ChangePassword.php` (~25 lines)
- Create: `tests/Handler/ChangePasswordTest.php` (port from `tests/Hooks/ChangePasswordTest.php`)
- Modify: `continuum.php`, `lib/Hooks.php`
- Delete: `tests/Hooks/ChangePasswordTest.php`

Pattern is identical to Task 10 — resolve, updateUser, sync. Skipping inline code; follow Task 10 mechanically. Commit message:

```
refactor(handlers): extract Handler\\ChangePassword
```

---

### Task 12: Extract `Handler\ChangePackage`

`Hooks::changePackage` → `Handler\ChangePackage::handle($params)`. Wires `ProductConfig` + `AttributeMapper` like CreateAccount but only the adoption branch. The cache-invalidation write moves into the handler (via `CustomFieldStore::write(serviceId, 'continuum_library_names_cache', '')`).

**Files:**
- Create: `lib/Handler/ChangePackage.php` (~50 lines)
- Create: `tests/Handler/ChangePackageTest.php` (port from `tests/Hooks/ChangePackageTest.php` + merge content of `ChangePackageInternalTest.php`)
- Modify: `continuum.php`, `lib/Hooks.php`
- Delete: `tests/Hooks/ChangePackageTest.php`, `tests/Hooks/ChangePackageInternalTest.php`

Body:

```php
final class ChangePackage
{
    public function __construct(
        private ClientInterface $client,
        private Resolver $resolver,
        private Sync $sync,
        private AttributeMapper $mapper,
        private CustomFieldStore $customFields,
    ) {}

    public function handle(array $params): string
    {
        try {
            $pc = ProductConfig::fromParams($params);
            $rs = ConfigurableOptionsRuleSet::fromJson($pc->configurableOptionsMapJson());
            $attrs = $this->mapper->apply($pc, $rs, $this->normaliseOptions($params['configoptions'] ?? []));
        } catch (\InvalidArgumentException $e) {
            return 'Product config error: ' . $e->getMessage();
        }

        $resolved = $this->resolver->resolve($params);
        if ($resolved === null) {
            return 'No Continuum user is linked to this service.';
        }

        try {
            $this->client->updateUser($resolved->userId, $attrs);
        } catch (ContinuumApiException $e) {
            return $e->httpStatus() >= 500
                ? 'Continuum returned a server error. Check Module Log for details.'
                : 'Continuum: ' . $e->getMessage();
        }

        $this->sync->align($resolved, $params);

        // Invalidate library-names cache (next ClientArea render refetches).
        try {
            $this->customFields->write(Params::serviceId($params), 'continuum_library_names_cache', '');
        } catch (\Throwable $e) {
            // non-fatal
        }
        return 'success';
    }

    /** (same normaliseOptions as CreateAccount — copy or factor into a trait if it grows) */
    private function normaliseOptions(mixed $raw): array { /* ... */ }
}
```

Commit message: `refactor(handlers): extract Handler\\ChangePackage`.

---

### Task 13: Extract `Handler\AdminReconcile`

Trivial — delegates to `ChangePackage` (same logic). Two ways: make the WHMCS button entry call `continuum_ChangePackage` directly, or keep a thin `Handler\AdminReconcile` wrapper for symmetry. Recommend the wrapper:

```php
final class AdminReconcile
{
    public function __construct(private ChangePackage $changePackage) {}
    public function handle(array $params): string { return $this->changePackage->handle($params); }
}
```

Commit message: `refactor(handlers): extract Handler\\AdminReconcile`.

---

### Task 14: Extract `Handler\AdminResetPassword`

Mints a 32-byte random password, pushes via `ClientInterface::updateUser`, writes back to WHMCS service via `ServiceWriter::writeServerPassword`.

**Files:**
- Create: `lib/Handler/AdminResetPassword.php` (~40 lines)
- Create: `tests/Handler/AdminResetPasswordTest.php` (port)
- Modify: `continuum.php`, `lib/Hooks.php`
- Delete the corresponding chunk of `tests/Hooks/AdminButtonsTest.php` (it merges into `AdminResetPasswordTest`)

Body:

```php
final class AdminResetPassword
{
    public function __construct(
        private ClientInterface $client,
        private Resolver $resolver,
        private Sync $sync,
        private ServiceWriter $service,
    ) {}

    public function handle(array $params): string
    {
        $resolved = $this->resolver->resolve($params);
        if ($resolved === null) {
            return 'No Continuum user is linked to this service.';
        }
        $password = $this->generate();
        try {
            $this->client->updateUser($resolved->userId, ['password' => $password]);
        } catch (ContinuumApiException $e) {
            return $e->httpStatus() >= 500
                ? 'Continuum returned a server error. Check Module Log for details.'
                : 'Continuum: ' . $e->getMessage();
        }
        $this->sync->align($resolved, $params);

        try {
            $this->service->writeServerPassword(Params::serviceId($params), $password);
        } catch (\Throwable $e) {
            return 'success (warning: failed to write back password to WHMCS service: ' . $e->getMessage() . ')';
        }
        return 'success';
    }

    private function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
```

Commit message: `refactor(handlers): extract Handler\\AdminResetPassword`.

---

### Task 15: Extract `Handler\AdminServicesTab`

The deep-link button HTML is now part of this handler's output (from Task 3). Uses `$resolved->user` directly — no `getUser` call.

**Files:**
- Create: `lib/Handler/AdminServicesTab.php` (~50 lines)
- Create: `tests/Handler/AdminServicesTabTest.php` (port from `tests/Hooks/AdminTabTest.php`)
- Modify: `continuum.php`, `lib/Hooks.php`
- Delete: `tests/Hooks/AdminTabTest.php`

Body sketch (HTML largely as in Task 3, but reading from `$resolved->user`):

```php
public function handle(array $params): array
{
    $resolved = $this->resolver->resolve($params);
    if ($resolved === null) {
        return ['Continuum status' => 'No Continuum user is linked. Run "Reconcile from WHMCS".'];
    }
    $this->sync->align($resolved, $params);
    $user = $resolved->user;
    $deepLink = htmlspecialchars($this->client->baseUrlForDeepLink() . "/admin/users/{$user->id}");
    // build $rows from $user->id, $user->email, $user->enabled, $user->role, $user->libraryIds, $user->maxStreams
    // append the deep-link <a> as the final row
    return ['Continuum status' => implode('', $rows)];
}
```

Commit message: `refactor(handlers): extract Handler\\AdminServicesTab`.

---

### Task 16: Extract `Handler\ClientArea`

The library-name cache logic is the bulk of this. Move `Hooks::resolveLibraryNames` into the handler (or its own small helper class if it grows past 30 lines).

**Files:**
- Create: `lib/Handler/ClientArea.php` (~80 lines including cache logic)
- Create: `tests/Handler/ClientAreaTest.php` (port from `tests/Hooks/ClientAreaTest.php`)
- Modify: `continuum.php`, `lib/Hooks.php`
- Delete: `tests/Hooks/ClientAreaTest.php`

Body sketch:

```php
public function handle(array $params): array
{
    $resolved = $this->resolver->resolve($params);
    if ($resolved === null) {
        return ['templatefile' => 'clientarea', 'vars' => [
            'error' => 'Your Continuum account is not yet linked. Contact support.',
        ]];
    }
    $this->sync->align($resolved, $params);
    $user = $resolved->user;
    $vars = [
        'status' => $user->enabled ? 'active' : 'suspended',
        'stream_limit' => $user->maxStreams,
        'quality' => $this->humanQuality((string)($user->raw['max_playback_quality'] ?? '')),
        'library_names' => $this->resolveLibraryNames($params, $user->libraryIds),
        'last_seen_relative' => $user->lastActiveAt ? $this->humanRelativeTime($user->lastActiveAt) : 'never',
        'login_url' => $this->client->baseUrlForDeepLink() . '/',
    ];
    return ['templatefile' => 'clientarea', 'vars' => $vars];
}
```

`humanQuality`, `humanRelativeTime`, `resolveLibraryNames` are private methods copied from `Hooks.php`.

Commit message: `refactor(handlers): extract Handler\\ClientArea`.

---

### Task 17: Delete `lib/Hooks.php`, simplify `continuum.php` with a factory function

After all 8 handlers extracted, `Hooks.php` is empty. Delete it. Add a small handler-factory in `continuum.php` to avoid the verbose `new` chains.

**Files:**
- Delete: `lib/Hooks.php`
- Delete: `tests/Hooks/` (entire directory should be empty now — `AdminButtonsTest.php` becomes `AdminResetPasswordTest` already)
- Modify: `continuum.php` — factor the repeated construction into one private function

`continuum.php`:

```php
<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/vendor/autoload.php';

use Continuum\WhmcsModule\AttributeMapper;
use Continuum\WhmcsModule\Client;
use Continuum\WhmcsModule\Config\ServerConfig;
use Continuum\WhmcsModule\Handler;
use Continuum\WhmcsModule\Identity\Resolver;
use Continuum\WhmcsModule\Identity\Sync;
use Continuum\WhmcsModule\Username\UsernameResolver;
use Continuum\WhmcsModule\Whmcs\CustomFieldStore;
use Continuum\WhmcsModule\Whmcs\ServiceWriter;

function continuum_MetaData(): array { /* unchanged */ }
function continuum_ConfigOptions(): array { /* unchanged */ }

function continuum_handlers(array $params): array
{
    $cfg = ServerConfig::fromParams($params);
    $client = new Client($cfg);
    $cf = new CustomFieldStore();
    $resolver = new Resolver($client);
    $sync = new Sync($client, $cf);
    $mapper = new AttributeMapper();
    $service = new ServiceWriter();
    $usernameResolver = new UsernameResolver($client);

    return [
        'createAccount'     => new Handler\CreateAccount($client, $resolver, $sync, $mapper, $cf, $service, $usernameResolver),
        'setEnabled'        => new Handler\SetEnabled($client, $resolver, $sync),
        'changePassword'    => new Handler\ChangePassword($client, $resolver, $sync),
        'changePackage'     => new Handler\ChangePackage($client, $resolver, $sync, $mapper, $cf),
        'adminReconcile'    => new Handler\AdminReconcile(new Handler\ChangePackage($client, $resolver, $sync, $mapper, $cf)),
        'adminResetPassword'=> new Handler\AdminResetPassword($client, $resolver, $sync, $service),
        'adminTab'          => new Handler\AdminServicesTab($client, $resolver, $sync),
        'clientArea'        => new Handler\ClientArea($client, $resolver, $sync, $cf),
    ];
}

function continuum_CreateAccount(array $params): string
{
    return continuum_handlers($params)['createAccount']->handle($params);
}

function continuum_SuspendAccount(array $params): string
{
    return continuum_handlers($params)['setEnabled']->handle($params, false);
}
// ... and so on for the other 6 entry points.

function continuum_AdminCustomButtonArray(): array
{
    return [
        'Reconcile from WHMCS' => 'admin_reconcile',
        'Reset Password' => 'admin_reset_password',
    ];
}

function continuum_admin_reconcile(array $params): string      { return continuum_handlers($params)['adminReconcile']->handle($params); }
function continuum_admin_reset_password(array $params): string { return continuum_handlers($params)['adminResetPassword']->handle($params); }

function continuum_AdminServicesTabFields(array $params): array { return continuum_handlers($params)['adminTab']->handle($params); }

function continuum_ClientAreaCustomButtonArray(): array { return []; }
function continuum_ClientArea(array $params): array { return continuum_handlers($params)['clientArea']->handle($params); }
```

- [ ] **Step 1: Run tests, verify all green before any deletion**

Run: `composer test`
Expected: green. (All extractions are done; `Hooks.php` should already be empty except for trivial scaffolding.)

- [ ] **Step 2: Delete `Hooks.php` and any remaining test files in `tests/Hooks/`**

```bash
git rm lib/Hooks.php
ls tests/Hooks/ 2>/dev/null && git rm -r tests/Hooks/
ls lib/HookContext.php 2>/dev/null   # check if still referenced
```

If `HookContext` is no longer used (handlers don't use it), delete that too. The factory in `continuum.php` replaces it.

- [ ] **Step 3: Run tests + lint, commit**

```bash
composer test && composer lint
git add continuum.php
git commit -m "refactor: delete Hooks.php; continuum.php constructs handlers directly

500-line god class replaced by 8 handler classes; continuum.php now
wires dependencies and dispatches per hook event.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Phase 5 — Reconcile completion

### Task 18: Scope `DailyReconciler` by `server_id` and build full expected state

Two fixes in one commit: filter `tblhosting` by server, and populate the full expected attribute set so `DriftCheck` actually fires every branch.

**Files:**
- Modify: `lib/DailyReconciler.php` (constructor takes `serverId`, query gets `->where('server', $serverId)`, expected state built via shared helper)
- Modify: `lib/DriftCheck.php` (add branches for `max_transcodes`, `max_profiles`, `max_playback_quality`, `download_allowed`, `download_transcode_allowed`)
- Modify: `hooks.php` (the cron hook passes `$server->id` to `DailyReconciler`)
- Create: `lib/Reconcile/ExpectedState.php` (or inline as a helper — extract `(ProductConfig, configurable options) → attrs` builder that `CreateAccount` and `ChangePackage` already use; share it)
- Modify: `tests/DailyReconcilerSentinelTest.php` and `tests/DriftCheckTest.php` (add coverage for the new branches)

(This task has the most substantial test additions. Plan: build the shared `ExpectedState` helper first with its tests, then wire reconciler to use it, then extend DriftCheck.)

- [ ] **Step 1: Extract a shared `Reconcile\ExpectedState` builder**

```php
<?php
declare(strict_types=1);
namespace Continuum\WhmcsModule\Reconcile;

use Continuum\WhmcsModule\AttributeMapper;
use Continuum\WhmcsModule\Config\ConfigurableOptionsRuleSet;
use Continuum\WhmcsModule\Config\ProductConfig;

final class ExpectedState
{
    public function __construct(private AttributeMapper $mapper) {}

    /**
     * @param array<string, mixed> $params       WHMCS hook params or hydrated service+product row
     * @param array<int, array{name: string, value: string}> $configOptions
     * @return array<string, mixed>
     */
    public function build(array $params, array $configOptions, bool $enabled): array
    {
        $pc = ProductConfig::fromParams($params);
        $rs = ConfigurableOptionsRuleSet::fromJson($pc->configurableOptionsMapJson());
        $attrs = $this->mapper->apply($pc, $rs, $configOptions);
        $attrs['enabled'] = $enabled;
        return $attrs;
    }
}
```

Test: `tests/Reconcile/ExpectedStateTest.php` exercises a few `ProductConfig` shapes and asserts the resulting attrs include `enabled` + all the standard fields.

- [ ] **Step 2: Update `Handler\CreateAccount` and `Handler\ChangePackage` to use `ExpectedState`**

Replace inline `$attrs = $this->mapper->apply($pc, $rs, $this->normaliseOptions(...))` with `$attrs = $this->expectedState->build($params, $configOptions, true)` (skipping the `enabled` key when sending to `createUser` — pop it off, or have `build` accept an "include enabled" flag).

- [ ] **Step 3: Update `DailyReconciler` to use `ExpectedState` and scope by serverId**

```php
final class DailyReconciler
{
    public function __construct(
        private int $serverId,
        private array $serverParams,
        private ExpectedState $expectedState,
    ) {}

    public function run(): void
    {
        $cfg = ServerConfig::fromParams($this->serverParams);
        if (!$cfg->reconcileDaily()) {
            return;
        }
        $client = new Client($cfg);

        $services = Capsule::table('tblhosting')
            ->where('domainstatus', 'Active')
            ->where('server', $this->serverId)
            ->get();

        foreach ($services as $svc) {
            $svcParams = $this->hydrateServiceParams($svc);   // builds $params-equivalent from DB row
            $configOptions = $this->loadConfigOptions((int)$svc->id);
            try {
                $expected = $this->expectedState->build($svcParams, $configOptions,
                    strtolower((string)$svc->domainstatus) === 'active');
            } catch (\InvalidArgumentException $e) {
                logActivity("continuum reconcile: service {$svc->id} config invalid: " . $e->getMessage());
                continue;
            }

            $userId = (int)($svcParams['customfields']['continuum_user_id'] ?? 0);
            if ($userId === 0) {
                logActivity("continuum reconcile: service {$svc->id} has no continuum_user_id; skipping");
                continue;
            }
            try {
                $observed = $client->getUser($userId);
            } catch (ContinuumApiException $e) {
                logActivity("continuum reconcile: service {$svc->id} → user {$userId}: " . $e->getMessage());
                continue;
            }
            foreach (DriftCheck::compare((int)$svc->id, $userId, $expected, $observed->raw) as $msg) {
                logActivity("continuum reconcile drift: {$msg}");
            }
        }
    }

    private function hydrateServiceParams(\stdClass $svc): array { /* read tblcustomfields + tblproducts for this service */ }
    private function loadConfigOptions(int $serviceId): array { /* read tblhostingconfigoptions joined to tblproductconfigoptionssub */ }
}
```

The `hydrateServiceParams` and `loadConfigOptions` are non-trivial — implement carefully, test against the `WhmcsFunctionStub`/`Capsule` mock setup. **Or** simpler: defer hydration to a separate small class `Whmcs\ServiceHydrator` so it can be tested independently. Recommend that split for clarity.

- [ ] **Step 4: Extend `DriftCheck::compare`**

Add branches for the missing attributes. Pattern:

```php
foreach (['max_transcodes', 'max_profiles', 'max_streams'] as $intField) {
    if (isset($expected[$intField]) && (int)($observed[$intField] ?? 0) !== (int)$expected[$intField]) {
        $drifts[] = "service {$serviceId} → user {$userId}: {$intField} expected={$expected[$intField]} "
            . "but Continuum has " . (int)($observed[$intField] ?? 0);
    }
}
foreach (['download_allowed', 'download_transcode_allowed'] as $boolField) {
    if (isset($expected[$boolField]) && ($observed[$boolField] ?? null) !== $expected[$boolField]) {
        $drifts[] = "service {$serviceId} → user {$userId}: {$boolField} expected="
            . var_export($expected[$boolField], true) . " but Continuum has "
            . var_export($observed[$boolField] ?? null, true);
    }
}
if (isset($expected['max_playback_quality'])
    && (string)($observed['max_playback_quality'] ?? '') !== (string)$expected['max_playback_quality']) {
    $drifts[] = "service {$serviceId} → user {$userId}: max_playback_quality expected='"
        . $expected['max_playback_quality'] . "' but Continuum has '"
        . (string)($observed['max_playback_quality'] ?? '') . "'";
}
```

- [ ] **Step 5: Update `hooks.php` cron registration**

```php
add_hook('DailyCronJob', 1, function ($vars) {
    $servers = Capsule::table('tblservers')->where('type', 'continuum')->get();
    foreach ($servers as $server) {
        try {
            $params = [
                'serverhostname' => $server->hostname,
                'serversecure' => $server->secure ? 'on' : '',
                'serverpassword' => decrypt($server->password),
                'reconcile_daily' => 'yes',
            ];
            (new DailyReconciler(
                (int)$server->id,
                $params,
                new ExpectedState(new AttributeMapper()),
            ))->run();
        } catch (\Throwable $e) {
            logActivity('continuum daily reconcile failed for server ' . $server->id . ': ' . $e->getMessage());
        }
    }
});
```

- [ ] **Step 6: Run tests + lint, commit.**

```bash
git add lib/Reconcile/ lib/DailyReconciler.php lib/DriftCheck.php hooks.php tests/
git commit -m "feat(reconcile): server_id-scoped query + full attribute drift coverage

DailyReconciler now filters by server (was walking all active services),
builds the full expected state via shared ExpectedState helper, and
DriftCheck fires on every attribute the module manages.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Phase 6 — Pagination + observability

### Task 19: Per-instance user-list cache + pagination follow + size warning in `Client`

**Files:**
- Modify: `lib/Client.php`
- Modify: `tests/ClientTest.php` (or `tests/Continuum/GuzzleClientTest.php` if renamed) — add cache + pagination tests

- [ ] **Step 1: Tests**

```php
public function testListsUsersOncePerInstanceAcrossLookups(): void
{
    $this->mock->append(new Response(200, [], json_encode([['id' => 1, 'email' => 'a@x', 'username' => 'x']])));
    $client = $this->newClient();
    $client->findUserByEmail('a@x');
    $client->findUserByUsername('x');
    $this->assertCount(1, $this->captured, 'list endpoint should be called once');
}

public function testFollowsPaginationLinkHeader(): void
{
    $this->mock->append(new Response(200, ['Link' => '</api/v1/admin/users?page=2>; rel="next"'],
        json_encode([['id' => 1, 'email' => 'a@x', 'username' => 'x']])));
    $this->mock->append(new Response(200, [], json_encode([['id' => 2, 'email' => 'b@x', 'username' => 'y']])));
    $client = $this->newClient();
    $u = $client->findUserByEmail('b@x');
    $this->assertSame(2, $u->id);
}

public function testLogsWarningAbove5000Users(): void
{
    \Continuum\WhmcsModule\Tests\WhmcsFunctionStub::reset();
    $bigList = [];
    for ($i = 1; $i <= 5001; $i++) {
        $bigList[] = ['id' => $i, 'email' => "u{$i}@x", 'username' => "user{$i}"];
    }
    $this->mock->append(new Response(200, [], json_encode($bigList)));
    $this->newClient()->findUserByEmail('u500@x');
    $this->assertNotEmpty(\Continuum\WhmcsModule\Tests\WhmcsFunctionStub::$activityLog);
    $this->assertStringContainsString('>5000', \Continuum\WhmcsModule\Tests\WhmcsFunctionStub::$activityLog[0]);
}
```

- [ ] **Step 2: Verify fails. Implement in `Client`:**

```php
/** @var array<int, array<string, mixed>>|null */
private ?array $userListCache = null;
private bool $warnedAboveThreshold = false;

private function loadUserList(): array
{
    if ($this->userListCache !== null) {
        return $this->userListCache;
    }
    $all = [];
    $path = '/api/v1/admin/users';
    while ($path !== null) {
        // existing jsonRequest variant that also returns the response object so we can read headers
        $res = $this->http->request('GET', $this->cfg->baseUrl() . $path, [
            'headers' => ['Authorization' => 'Bearer ' . $this->cfg->apiKey(), 'Accept' => 'application/json'],
            'http_errors' => false,
        ]);
        $body = json_decode((string)$res->getBody(), true);
        if (!is_array($body)) {
            break;
        }
        $all = array_merge($all, $body);
        $path = $this->nextPagePath($res->getHeaderLine('Link'));
    }
    if (count($all) > 5000 && !$this->warnedAboveThreshold) {
        $this->warnedAboveThreshold = true;
        if (function_exists('logActivity')) {
            logActivity('continuum: user list >5000 — consider adding email-filter endpoint on Continuum side');
        }
    }
    $this->userListCache = $all;
    return $all;
}

private function nextPagePath(string $linkHeader): ?string
{
    if ($linkHeader === '') {
        return null;
    }
    if (!preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $m)) {
        return null;
    }
    return parse_url($m[1], PHP_URL_PATH) . (parse_url($m[1], PHP_URL_QUERY) ? '?' . parse_url($m[1], PHP_URL_QUERY) : '');
}
```

`findUserByEmail` and `findUserByUsername` switch to using `loadUserList()` and scanning the result.

- [ ] **Step 3: Verify pass + lint + commit.**

```bash
git add lib/Client.php tests/ClientTest.php
git commit -m "feat(continuum): cached user-list with pagination follow + size warning

CreateAccount today calls findUserByEmail then findUserByUsername back
to back; both now share one fetch. Pagination follow is future-proof
(currently no-op against Continuum). One-shot logActivity above 5000
users nudges toward adding a server-side filter endpoint.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Phase 7 — Docs alignment

### Task 20: Rewrite README + IMPLEMENTATION_NOTES + whmcs-contracts.md

**Files:**
- Modify: `README.md`
- Modify: `IMPLEMENTATION_NOTES.md`
- Modify: `docs/whmcs-contracts.md`

- [ ] **Step 1: Update `README.md`**

- Architecture section: describe the handler-per-hook layout. Replace any references to `Hooks.php` with the new structure.
- Setup section: unchanged (custom fields still required, same names).
- Troubleshooting: drop the orphan-error entry (already done in a prior commit).
- Customer-chosen usernames section: paths now reference `lib/Username/` instead of root `lib/`.

- [ ] **Step 2: Update `IMPLEMENTATION_NOTES.md`**

- Delete the "not final because of mocks" carve-out (already done in Task 4).
- Add a "Refactor 2026-05-13" section summarising the layout move, the two contract bugs fixed, the reconciler completion, the pagination guard.
- Phase 14.2 (live-WHMCS smoke) note now points out that bugs 1 and 2 are the specific items the smoke would have caught — explicitly call them out as the items to verify post-deploy.

- [ ] **Step 3: Update `docs/whmcs-contracts.md`**

- Mark §1 ($params shape) as VERIFIED with citation: https://developers.whmcs.com/provisioning-modules/module-parameters/
- Mark §2 (UpdateClientProduct customfields) as VERIFIED + FIXED: customfields must be keyed by numeric `tblcustomfields.id`. Citation: https://developers.whmcs.com/api-reference/updateclientproduct/
- §3 (GetClientsProducts response) stays partially verified; cite the test that validates the actual shape.
- §4 (Admin button return type) mark VERIFIED + FIXED: must return string; deep-link moved into AdminServicesTabFields HTML. Citation: https://developers.whmcs.com/provisioning-modules/custom-functions/
- §5 (ClientArea return) mark VERIFIED. Citation: https://developers.whmcs.com/provisioning-modules/client-area-output/
- §6 (ClientEdit payload) mark VERIFIED. Citation: https://developers.whmcs.com/hooks-reference/client/

- [ ] **Step 4: Commit.**

```bash
git add README.md IMPLEMENTATION_NOTES.md docs/whmcs-contracts.md
git commit -m "docs: align README/IMPLEMENTATION_NOTES/whmcs-contracts with refactor

Marks Phase 5b items 1, 2, 4, 5, 6 as VERIFIED via developers.whmcs.com;
items 2 and 4 also marked FIXED (the two contract bugs). Architecture
section in README rewritten to describe handler-per-hook layout.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Self-review notes

- **Spec coverage:** Every section of the design spec maps to at least one task. Identity\Resolver → Task 7. Identity\Sync → Task 8. CustomFieldStore name→ID → Task 1. Admin deep-link relocation → Task 3. ClientInterface + User → Tasks 4-5. Params extraction → Task 6. Handler split → Tasks 9-17. DailyReconciler completion → Task 18. Pagination + warning → Task 19. Docs → Task 20.
- **Placeholder check:** No "TBD" / "TODO" / "fill in" / "similar to Task N" left in the plan. Where a task says "follow Task N mechanically" (Task 11), the structure is small enough and Task 10's body is right above for reference.
- **Type/name consistency:** `ClientInterface` everywhere (not `Client` or `ContinuumClient`). `Resolved` carries `userId`, `source`, `user`. `Sync::align` is the only method name. `CustomFieldStore::write` / `read` / `declaredFieldNames` consistent across tasks.

## Acceptance gates

After Task 20:
- `composer test` reports the same or higher test count as today; all green.
- `composer lint` clean.
- `continuum.php` exports the same `continuum_*` function names as on `main` at start of refactor (verify via `grep -c '^function continuum_' continuum.php` matches before and after).
- `lib/Hooks.php` no longer exists.
- `docs/whmcs-contracts.md` has citation URLs for the 5 verified items.
