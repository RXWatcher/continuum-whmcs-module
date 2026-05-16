# WHMCS module refactor — design spec

**Date:** 2026-05-13
**Status:** Approved for implementation planning
**Predecessor:** the current `main` (commit `78597a1` — three-tier linkage + ClientEdit sync)

## 1. Motivation

The current module works end-to-end against its mocked test environment
(107 tests / 646 assertions green) but two structural problems remain:

1. **Two real WHMCS contract bugs that tests cannot catch.** Both come from
   the Phase 5b verification deferral and would surface only on a live
   WHMCS deploy.
2. **Internal coupling has accumulated.** `lib/Hooks.php` is a 500-line
   class with 12 public methods covering every WHMCS hook event the module
   handles; `Identity` mixes static parameter extractors with an instance
   resolver; the `not-final-because-of-mocks` carve-out documents friction
   that an interface would resolve cleanly.

The refactor is a **restructure-plus-robustness pass** that keeps the
module's external surface (the `continuum_*` function exports in
`continuum.php` and `hooks.php`) byte-identical, fixes the two contract
bugs, splits the monolithic handler into one class per hook, introduces a
typed interface between this module and Continuum's HTTP API, and closes
several robustness gaps that today exist as `// TODO` comments in
`DailyReconciler` and `Client`.

No new product features are in scope.

## 2. Contract verification — verified facts and bugs

WHMCS developer documentation was fetched live during design. Six
assumptions from `docs/whmcs-contracts.md` were checked.

### Verified correct (no code change needed)

| Assumption | Verification | Source |
|---|---|---|
| `$params['customfields']` keyed by NAME | Confirmed | [WHMCS module-parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/) |
| `$params['configoptions']` keyed by NAME | Confirmed | [WHMCS module-parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/) |
| `ClientArea` returns `{templatefile, vars}` | Confirmed | [WHMCS client-area-output](https://developers.whmcs.com/provisioning-modules/client-area-output/) |
| `ClientEdit` payload has `userid`, `email`, `olddata.email` | Confirmed | [WHMCS hooks-reference/client](https://developers.whmcs.com/hooks-reference/client/) |

### Bugs requiring fixes

**Bug #1 — `UpdateClientProduct` customfields must be keyed by numeric field ID, not name.**

The current code calls
`base64_encode(serialize(['continuum_user_id' => $value]))`. WHMCS's
official documentation and community confirm the array must use the
numeric `tblcustomfields.id` as the key; sending a name results in the
API returning `success` but **silently doing nothing**.

The dual-linkage in `Identity::resolve` (email + username fallbacks) is
what hides the bug today — the module still functions, but every
"persistent" custom field is empty on real WHMCS, every lookup falls
through to the email or username heal, and the value of having a custom
field at all is zero.

Sources: [WHMCS UpdateClientProduct](https://developers.whmcs.com/api-reference/updateclientproduct/),
[community thread](https://whmcs.community/topic/156361-api-updateclientproduct-customfields/).

**Bug #2 — Custom-button handlers must return strings, not arrays.**

`Hooks::adminOpenInContinuum` returns `{redirect, newWindow}`. WHMCS's
custom-function documentation defines the return shape as a string
(`'success'` or an error message). The newer **Custom Actions** feature
(WHMCS 8.5+) supports `{success, redirectTo}` but has no `newWindow`
flag — clicking it navigates away from WHMCS, which is not the UX we
want for a deep-link.

Resolution: drop the button. Render the deep-link as
`<a href="..." target="_blank" rel="noopener">` inside
`AdminServicesTabFields` output, which is documented to accept arbitrary
HTML.

Sources: [WHMCS custom-functions](https://developers.whmcs.com/provisioning-modules/custom-functions/),
[WHMCS custom-actions](https://developers.whmcs.com/provisioning-modules/custom-actions/).

## 3. Target module layout

```
continuum.php                          (WHMCS dispatch table — surface unchanged)
hooks.php                              (DailyCronJob + ClientEdit — surface unchanged)

lib/
  Handler/
    CreateAccount.php
    SetEnabled.php              (shared by Suspend, Unsuspend, Terminate)
    ChangePassword.php
    ChangePackage.php
    AdminReconcile.php          (delegates to ChangePackage)
    AdminResetPassword.php
    AdminServicesTab.php        (hosts the deep-link button)
    ClientArea.php
  Continuum/
    ClientInterface.php
    GuzzleClient.php            (current Client.php, behind the interface)
    User.php                    (typed value object)
  Whmcs/
    CustomFieldStore.php        (resolves field name → ID, then reads/writes)
    ServiceWriter.php           (writes tblhosting.username + serverpassword)
    ProductLookup.php           (probe for required custom fields)
  Identity/
    Resolver.php                (three-tier resolve, returns Resolved)
    Params.php                  (pure static extractors)
    Sync.php                    (align() — pushes drifted fields + heals linkage)
    Resolved.php                (value object: id, source, user)
  Reconcile/
    DailyReconciler.php         (server_id-scoped, full attribute coverage)
    DriftCheck.php              (extended assertions)
  Config/
    ServerConfig.php
    ProductConfig.php
    ConfigurableOptionsRule.php
    ConfigurableOptionsRuleSet.php
  Username/
    Generator.php
    Validator.php
    BadWordList.php
    UsernameResolver.php        (chosen-vs-generated branching)
  AttributeMapper.php
  HookContext.php               (DI container, wires defaults from $params)
  ClientEditSync.php
  ContinuumApiException.php

data/
  bad_words.default.txt

templates/
  clientarea.tpl

tests/
  Handler/...
  Continuum/...
  Whmcs/...
  Identity/...
  Reconcile/...
  Config/...
  Username/...
  AttributeMapperTest.php
  ClientEditSyncTest.php
  bootstrap.php
  WhmcsFunctionStub.php
```

Key principles:
- One handler = one file = one public method.
- All WHMCS-side IO behind named writers (`CustomFieldStore`,
  `ServiceWriter`, `ProductLookup`). Nothing in `Handler/*` calls
  `localAPI` directly.
- Continuum-side IO behind `ClientInterface`. `final` is the default.

## 4. Internal abstractions

### `Continuum\ClientInterface`

```php
interface ClientInterface
{
    public function createUser(array $payload): User;
    public function updateUser(int $userId, array $payload): User;
    public function deleteUser(int $userId): void;
    public function getUser(int $userId): User;
    public function findUserByEmail(string $email): ?User;       // lowercased internally
    public function findUserByUsername(string $username): ?User;
    /** @return array<int, array{id:int, name:string}> */
    public function listLibraries(): array;
    public function baseUrlForDeepLink(): string;
}
```

`GuzzleClient` is `final class implements ClientInterface` — current
`Client.php` body, minimal changes. Tests
`createMock(ClientInterface::class)`. The
"not-final-because-of-mocks" carve-out in `IMPLEMENTATION_NOTES.md` is
deleted.

### `Continuum\User`

```php
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,        // lowercased on construction
        public readonly string $username,
        public readonly bool $enabled,
        public readonly string $role,
        /** @var int[] */ public readonly array $libraryIds,
        public readonly int $maxStreams,
        public readonly ?string $lastActiveAt,
        public readonly array $raw,           // full body for unmodeled fields
    ) {}

    public static function fromApi(array $body): self { /* ... */ }
}
```

Catches field-name typos at construction (`last_seen_at` vs
`last_active_at`); `raw` is the forward-compat escape hatch.

### `Identity\Resolver`, `Identity\Params`, `Identity\Sync`, `Identity\Resolved`

```php
final class Params   // pure static extractors
{
    public static function email(array $params): string;       // lowercased trimmed
    public static function username(array $params): string;
    public static function continuumUserId(array $params): ?int;
    public static function serviceId(array $params): int;
    public static function password(array $params): string;
}

final class Resolver
{
    public function __construct(private ClientInterface $client) {}
    public function resolve(array $params): ?Resolved;
}

final class Resolved
{
    public function __construct(
        public readonly int $userId,
        public readonly string $source,    // 'id' | 'email' | 'username'
        public readonly User $user,         // the full fetched/found Continuum user
    ) {}
}

final class Sync
{
    public function __construct(
        private ClientInterface $client,
        private CustomFieldStore $customFields,
    ) {}

    public function align(Resolved $resolved, array $params): void
    {
        // 1. If $resolved->source !== 'id', write the discovered id to the custom field.
        // 2. If WHMCS email differs from $resolved->user->email, push WHMCS email.
        // 3. If WHMCS username differs from $resolved->user->username, push WHMCS username.
        // No-op when everything already aligns.
    }
}
```

Two knock-on benefits:
- `Resolved::$user` is the user object the resolver already fetched.
  Handlers like `ClientArea` and `AdminServicesTab` skip their own
  `getUser` round trip — roughly 20% fewer Continuum calls in steady
  state.
- `Sync::align` is one verb that replaces today's scattered
  `syncFields` / `ensureLinkage` calls. Handlers stop reasoning about
  what to push back.

### `Whmcs\CustomFieldStore`

```php
final class CustomFieldStore
{
    /** @var array<int, array<string, int>>  serviceId => [fieldName => fieldId] */
    private array $idsByService = [];

    public function write(int $serviceId, string $fieldName, string $value): void
    {
        $id = $this->resolveId($serviceId, $fieldName);
        // localAPI('UpdateClientProduct', [
        //     'serviceid' => $serviceId,
        //     'customfields' => base64_encode(serialize([$id => $value])),
        // ]);
    }

    public function read(int $serviceId, string $fieldName): ?string;

    private function resolveId(int $serviceId, string $fieldName): int
    {
        // Memoised per service. Resolution order:
        //   1. tblcustomfields WHERE type='product' AND relid=<productid> AND fieldname=<name>
        //   2. GetClientsProducts(serviceid) → products.product[0].customfields.customfield[]
        // Throw CustomFieldNotDeclared if neither resolves.
    }
}
```

Memoisation is hook-scoped — the `CustomFieldStore` instance is
constructed by `HookContext` once per WHMCS hook invocation and lives
only for that call's duration. The `Capsule` query uses bound
parameters; no injection risk on the operator-supplied field name.

### Test mocks

`createMock(ClientInterface::class)`, `createMock(CustomFieldStore::class)`,
`createMock(ServiceWriter::class)`, `createMock(ProductLookup::class)`.
`Handler/*Test.php` no longer needs `WhmcsFunctionStub::$localApi`
closures.

Only `CustomFieldStoreTest`, `ServiceWriterTest`, `ProductLookupTest`,
`ClientEditSyncTest`, and `DailyReconcilerTest` keep the WHMCS function
stubs (they're the boundary tests).

## 5. Handler pattern

Every handler is the same shape:

```php
final class SomeHandler
{
    public function __construct(
        private ClientInterface $continuum,
        private Resolver $resolver,
        private Sync $sync,
        private CustomFieldStore $customFields,   // only handlers that touch them
        private ServiceWriter $service,            // only handlers that touch tblhosting
        private AttributeMapper $mapper,           // only handlers that compute attrs
    ) {}

    public function handle(array $params): string|array { /* small body */ }
}
```

`HookContext::fromParams($params)` constructs the dependencies; tests
pass mocks. No `static`, no globals, no `new` inside handler bodies
(except value objects).

### Worked example — `Handler\CreateAccount`

```php
public function handle(array $params): string
{
    $serviceId = Params::serviceId($params);
    if ($missing = $this->probe->missingFields($serviceId)) {
        return $this->missingFieldsError($missing);
    }

    try {
        $pc = ProductConfig::fromParams($params);
        $rs = ConfigurableOptionsRuleSet::fromJson($pc->configurableOptionsMapJson());
        $attrs = $this->mapper->apply($pc, $rs, $this->configOptions($params));
    } catch (\InvalidArgumentException $e) {
        return 'Configuration error: ' . $e->getMessage();
    }

    if ($resolved = $this->resolver->resolve($params)) {
        try {
            $this->continuum->updateUser($resolved->userId, $attrs);
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
    try {
        $username = $this->usernameResolver->resolve($params, $pc);
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    try {
        $user = $this->continuum->createUser(array_merge($attrs, [
            'email' => $email,
            'username' => $username,
            'password' => Params::password($params),
            'create_default_profile' => $pc->createDefaultProfile(),
            'default_profile_name' => $this->defaultProfileName($params, $email),
        ]));
    } catch (ContinuumApiException $e) {
        return $this->humanError($e);
    }

    $this->customFields->write($serviceId, 'continuum_user_id', (string)$user->id);
    $this->service->writeUsername($serviceId, $user->username);
    return 'success';
}
```

Other handlers compress to 20-50 lines each. The previous 500-line
`Hooks.php` is gone.

## 6. Robustness pass

### `DailyReconciler` completion

1. **Server scoping.** Add `WHERE server = $serverId` to the
   `tblhosting` query. The cron hook in `hooks.php` already iterates
   servers and constructs one reconciler per server — pass the `id`
   through. Drops the "walks all active services across all products"
   bug.
2. **Full expected state.** Today's expected state is `['enabled' => ...]`
   only. Build the full attribute set via the same
   `(ProductConfig, configurable options) → attrs` helper that
   `CreateAccount` and `ChangePackage` use. `DriftCheck` already has
   most of the branches; they're just unreached.
3. **`DriftCheck::compare` extended.** Add branches for `max_transcodes`,
   `max_profiles`, `max_playback_quality`, `download_allowed`,
   `download_transcode_allowed`. The reconciler now logs actionable
   drift for every attribute the module manages.

The reconciler still does not auto-correct — admins click "Reconcile
from WHMCS" per service to apply. Unchanged from current.

### Pagination guard on `findUserBy*`

1. **Instance-scoped list cache** in `GuzzleClient`. `CreateAccount`
   today calls `findUserByEmail` then `findUserByUsername` back-to-back;
   both hit `/admin/users`. One fetch, two scans.
2. **Follow pagination if present.** Wrap the list call in a small loop
   that follows `Link: rel="next"` headers or `?page=N` until empty.
   No-op against current Continuum (one page); future-proof.
3. **Soft warning above 5000 users.** One-shot `logActivity` per cron
   run nudging toward "add `?email=` filter on Continuum side". The
   real long-term fix is a server-side filter endpoint; the warning
   surfaces the need.

### Not in scope

- No retry layer on transient Continuum 5xx (WHMCS retry semantics
  already cover this — admin clicks button again; cron retries
  tomorrow).
- No queue-based async reconcile. Lazy heal in `Identity\Resolver`
  + daily cron is sufficient for current scale.
- No persistence layer beyond WHMCS's own (`tblhosting`,
  `tblcustomfields`, `tblservers`).

## 7. Testing strategy

- **Mocks via interfaces.** `createMock(ClientInterface::class)`,
  `createMock(CustomFieldStore::class)`, etc. `final` is the default
  everywhere.
- **WHMCS function stubs slim down.** `tests/bootstrap.php` +
  `WhmcsFunctionStub.php` stay (only sane way to fake `localAPI`,
  `logActivity`, `decrypt`, `Capsule::table`). But handler tests stop
  using them — only `CustomFieldStoreTest`, `ServiceWriterTest`,
  `ProductLookupTest`, `ClientEditSyncTest`, and `DailyReconcilerTest`
  exercise the stub.
- **Test directory mirrors `lib/`.** `tests/Handler/`, `tests/Continuum/`,
  `tests/Whmcs/`, `tests/Identity/`, `tests/Reconcile/`,
  `tests/Username/`, etc.
- **New coverage worth flagging:**
  - `CustomFieldStoreTest::testSerialisesByNumericFieldId` — locks bug
    #1 fix into the test suite.
  - `AdminServicesTabTest::testDeepLinkButtonRendered` — locks bug #2
    fix.
  - `ResolverTest::testResolvedCarriesFetchedUser` — proves the
    no-double-getUser optimisation.
  - `DailyReconcilerTest::testDriftsAcrossAllManagedAttributes` —
    proves the completed reconciler.
- **Integration test against live WHMCS still deferred.** Phase 14.2
  remains an operator pre-deploy step. Contract bugs 1 and 2 are now
  the only items the WHMCS-side smoke can newly catch — the smoke
  checklist in `IMPLEMENTATION_NOTES.md` gets updated in Phase 7 to
  flag these specifically as items that validate the fix.

## 8. Migration plan

The refactor lands in seven phases; each phase leaves tests + lint
green and the module working.

### Phase 1 — contract bug fixes (must-fix before first deploy)

1. `feat(whmcs): CustomFieldStore resolves field name → numeric ID`
2. `feat(admin): deep-link moved into AdminServicesTabFields HTML`

### Phase 2 — Continuum-side abstractions

3. `refactor(continuum): introduce ClientInterface + User value object`

### Phase 3 — Identity extraction

4. `refactor(identity): split Resolver, Params, Sync, Resolved`

### Phase 4 — handler split

5. `refactor(handlers): one class per WHMCS hook event`

   May be sub-split into 8 commits (one handler per commit) if the diff
   feels too large for a single review.

### Phase 5 — reconcile completion

6. `feat(reconcile): server_id-scoped query + full attribute coverage`

### Phase 6 — pagination and observability

7. `feat(continuum): cached user-list with pagination follow + size warning`

### Phase 7 — docs alignment

8. `docs: rewrite README/IMPLEMENTATION_NOTES/whmcs-contracts after refactor`

### Verification gates

- After each phase: `composer test` green, `composer lint` green.
- Phase 4 specifically: assert the public `continuum_*` function
  exports in `continuum.php` are byte-identical pre/post.

## 9. References

- [WHMCS Module Parameters](https://developers.whmcs.com/provisioning-modules/module-parameters/)
- [WHMCS UpdateClientProduct](https://developers.whmcs.com/api-reference/updateclientproduct/)
- [WHMCS GetClientsProducts](https://developers.whmcs.com/api-reference/getclientsproducts/)
- [WHMCS Client Area Output](https://developers.whmcs.com/provisioning-modules/client-area-output/)
- [WHMCS Custom Functions](https://developers.whmcs.com/provisioning-modules/custom-functions/)
- [WHMCS Custom Actions (8.5+)](https://developers.whmcs.com/provisioning-modules/custom-actions/)
- [WHMCS Hooks: client events](https://developers.whmcs.com/hooks-reference/client/)
- [WHMCS community: customfields by ID vs name](https://whmcs.community/topic/156361-api-updateclientproduct-customfields/)
