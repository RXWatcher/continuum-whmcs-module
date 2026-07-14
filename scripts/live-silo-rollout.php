<?php

declare(strict_types=1);

use Silo\WhmcsModule\HookContext;
use Silo\WhmcsModule\Identity\Params;
use Silo\WhmcsModule\Whmcs\ExistingSiloUsernameRenamer;
use WHMCS\Database\Capsule;

if (PHP_SAPI !== 'cli') {
    exit(64);
}

require '/opt/whmcs/init.php';
require_once ROOTDIR . '/includes/modulefunctions.php';
require_once ROOTDIR . '/modules/servers/silo/autoload.php';

const PRODUCT_ID = 122;
const SILO_SERVER_ID = 123;
const EMBY_GROUP_ID = 9;
const SILO_GROUP_ID = 15;
const STATE_FILE = '/root/silo-live-rollout-state.json';
const JOURNAL_FILE = '/root/silo-live-rollout-journal.jsonl';

$mode = $argv[1] ?? '--dry-run';
$clientMode = preg_match('/^--client=(\d+)$/', $mode, $clientMatch) === 1;
if (!in_array($mode, ['--dry-run', '--canary', '--all'], true) && !$clientMode) {
    fwrite(STDERR, "Usage: php live-silo-rollout.php [--dry-run|--canary|--all|--client=ID]\n");
    exit(64);
}

if (!$clientMode) {
    $lock = fopen('/root/silo-live-rollout.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another rollout process is running');
    }
}

/** @return array<string, mixed> */
function state(): array
{
    if (!is_file(STATE_FILE)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents(STATE_FILE), true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $state */
function writeState(array $state): void
{
    $tmp = STATE_FILE . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    chmod($tmp, 0600);
    rename($tmp, STATE_FILE);
}

/** @param array<string, mixed> $event */
function journal(array $event): void
{
    $event['at'] = gmdate('c');
    file_put_contents(JOURNAL_FILE, json_encode($event, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    chmod(JOURNAL_FILE, 0600);
}

/** @return int[] */
function candidateIds(): array
{
    $activeEmby = Capsule::table('tblhosting as h')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where('p.gid', EMBY_GROUP_ID)
        ->where('h.domainstatus', 'Active')
        ->distinct()->pluck('h.userid')->map(static fn($id): int => (int)$id)->all();
    $withSilo = Capsule::table('tblhosting as h')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where('p.gid', SILO_GROUP_ID)
        ->distinct()->pluck('h.userid')->map(static fn($id): int => (int)$id)->all();
    $ids = array_values(array_diff(array_unique($activeEmby), array_unique($withSilo)));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/** @return array<string, mixed> */
function service(int $id): array
{
    $row = Capsule::table('tblhosting')->where('id', $id)->first();
    return $row === null ? [] : (array)$row;
}

/** @return array<string, mixed> */
function callApi(string $action, array $params): array
{
    $result = localAPI($action, $params);
    if (!is_array($result) || ($result['result'] ?? '') !== 'success') {
        throw new RuntimeException($action . ' failed: ' . json_encode($result));
    }
    return $result;
}

/** @param array<string, mixed> $runState */
function renameExisting(array &$runState): void
{
    if (($runState['existing_renames_complete'] ?? false) === true) {
        return;
    }
    $rows = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->where('h.packageid', PRODUCT_ID)
        ->where('h.domainstatus', 'Active')
        ->select('h.id', 'h.username', 'c.firstname', 'c.lastname')
        ->orderBy('h.id')->get();
    if (count($rows) !== 2) {
        throw new RuntimeException('Expected exactly 2 pre-rollout active product-122 services; found ' . count($rows));
    }
    foreach ($rows as $row) {
        $serviceId = (int)$row->id;
        $params = ModuleBuildParams($serviceId);
        if (!is_array($params)) {
            throw new RuntimeException("Could not build module params for service {$serviceId}");
        }
        $server = Capsule::table('tblservers')->where('id', (int)($params['serverid'] ?? 0))->first();
        if ($server === null) {
            throw new RuntimeException("Could not load Silo server for service {$serviceId}");
        }
        $params['serverhostname'] = (string)$server->hostname;
        $params['serverport'] = (string)$server->port;
        $params['serversecure'] = trim((string)$server->secure) !== '' ? 'on' : '';
        $params['serverpassword'] = function_exists('decrypt')
            ? decrypt((string)$server->password)
            : (string)$server->password;
        $ctx = HookContext::fromParams($params);
        $userId = $ctx->identity()->resolve($params, strict: true);
        if ($userId === null) {
            $model = \WHMCS\Service\Service::find($serviceId);
            $created = $model?->legacyProvision();
            journal(['event' => 'existing_service_create', 'service_id' => $serviceId, 'result' => $created]);
            if ($created !== true && $created !== 'success') {
                throw new RuntimeException("Could not provision existing service {$serviceId}: " . json_encode($created));
            }
            $params = ModuleBuildParams($serviceId);
            $server = Capsule::table('tblservers')->where('id', (int)($params['serverid'] ?? 0))->first();
            $params['serverhostname'] = (string)$server->hostname;
            $params['serverport'] = (string)$server->port;
            $params['serversecure'] = trim((string)$server->secure) !== '' ? 'on' : '';
            $params['serverpassword'] = decrypt((string)$server->password);
            $ctx = HookContext::fromParams($params);
            $userId = $ctx->identity()->resolve($params, strict: true);
            if ($userId === null) {
                throw new RuntimeException("Existing service {$serviceId} provisioned without a resolvable Silo user");
            }
            journal(['event' => 'existing_service_created', 'service_id' => $serviceId, 'user_id' => $userId]);
            continue;
        }
        $renamer = new ExistingSiloUsernameRenamer(
            $ctx->client(),
            static function (int $id, string $username): void {
                callApi('UpdateClientProduct', ['serviceid' => $id, 'serviceusername' => $username]);
            },
            static fn(int $id): string => (string)Capsule::table('tblhosting')->where('id', $id)->value('username'),
        );
        $result = $renamer->rename(
            $serviceId,
            $userId,
            (string)$row->username,
            (string)$row->firstname,
            (string)$row->lastname,
        );
        journal(['event' => 'existing_rename', 'result' => $result]);
        if (!$result['success'] || $result['critical_mismatch']) {
            throw new RuntimeException('Existing username rename failed for service ' . $serviceId . ': ' . $result['error']);
        }
    }
    $runState['existing_renames_complete'] = true;
    writeState($runState);
}

/** @return array{client_id:int,order_id:int,service_id:int} */
function provision(int $clientId): array
{
    if (!in_array($clientId, candidateIds(), true)) {
        throw new RuntimeException("Client {$clientId} is no longer eligible");
    }
    $emailMark = (int)(Capsule::table('tblemails')->max('id') ?? 0);
    $add = callApi('AddOrder', [
        'clientid' => $clientId,
        'paymentmethod' => 'stripe_dynamic',
        'pid' => [PRODUCT_ID],
        'billingcycle' => ['free'],
        'priceoverride' => ['0.00'],
        'noinvoice' => true,
        'noinvoiceemail' => true,
        'noemail' => true,
    ]);
    $orderId = (int)($add['orderid'] ?? 0);
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($add['serviceids'] ?? '')))));
    if ($orderId <= 0 || count($ids) !== 1) {
        throw new RuntimeException('Unexpected AddOrder response: ' . json_encode($add));
    }
    $serviceId = $ids[0];
    callApi('AcceptOrder', [
        'orderid' => $orderId,
        'serverid' => SILO_SERVER_ID,
        'autosetup' => true,
        'sendemail' => false,
    ]);
    callApi('UpdateClientProduct', [
        'serviceid' => $serviceId,
        'billingcycle' => 'Free',
        'recurringamount' => '0.00',
    ]);
    $svc = service($serviceId);
    foreach ([
        'packageid' => PRODUCT_ID,
        'server' => SILO_SERVER_ID,
        'domainstatus' => 'Active',
        'billingcycle' => 'Free',
        'firstpaymentamount' => '0.00',
        'amount' => '0.00',
    ] as $field => $expected) {
        if ((string)($svc[$field] ?? '') !== (string)$expected) {
            throw new RuntimeException("Service {$serviceId} validation failed for {$field}: " . json_encode($svc[$field] ?? null));
        }
    }
    $emails = Capsule::table('tblemails')->where('id', '>', $emailMark)->where('userid', $clientId)->count();
    if ($emails > 0) {
        throw new RuntimeException("Email activity detected for client {$clientId}");
    }
    $result = ['client_id' => $clientId, 'order_id' => $orderId, 'service_id' => $serviceId];
    journal(['event' => 'provision', 'result' => $result]);
    return $result;
}

$ids = candidateIds();
echo json_encode(['mode' => $mode, 'eligible' => count($ids), 'client_ids' => $ids]), "\n";
if ($mode === '--dry-run') {
    exit(0);
}

if ($clientMode) {
    echo json_encode(['result' => provision((int)$clientMatch[1])]), "\n";
    exit(0);
}

$runState = state();
renameExisting($runState);
Capsule::table('tblproducts')->where('id', PRODUCT_ID)->update(['name' => 'S - Service']);

if ($mode === '--canary') {
    if (($runState['canary_complete'] ?? false) === true) {
        throw new RuntimeException('Canary already completed');
    }
    $result = provision($ids[0] ?? 0);
    $runState['canary_complete'] = true;
    $runState['canary'] = $result;
    writeState($runState);
    echo json_encode(['canary' => $result]), "\n";
    exit(0);
}

if (($runState['canary_complete'] ?? false) !== true) {
    throw new RuntimeException('Run --canary successfully before --all');
}

$success = 0;
$failures = [];
foreach (candidateIds() as $clientId) {
    try {
        provision($clientId);
        $success++;
    } catch (Throwable $e) {
        $failures[] = ['client_id' => $clientId, 'error' => $e->getMessage()];
        journal(['event' => 'failure', 'client_id' => $clientId, 'error' => $e->getMessage()]);
    }
}
echo json_encode(['success' => $success, 'failures' => $failures]), "\n";
exit($failures === [] ? 0 : 1);
