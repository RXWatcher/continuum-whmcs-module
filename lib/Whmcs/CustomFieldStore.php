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
        if (($resp['result'] ?? '') !== 'success') {
            throw new \RuntimeException('GetClientsProducts returned: ' . json_encode($resp));
        }
        return $resp['products']['product'][0]['customfields']['customfield'] ?? [];
    }
}
