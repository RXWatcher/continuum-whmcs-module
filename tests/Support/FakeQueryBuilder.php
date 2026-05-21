<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Tests\Support;

/**
 * Minimal Laravel-ish query builder backing the Capsule fake. Honours only
 * simple equality `where('col', $val)` / `where('col', '=', $val)`
 * constraints — enough for the module's reads (tblhosting.domainstatus,
 * tblhosting.packageid) and tblcustomfields existence/insert. Closure /
 * orWhere / `like` grouped constraints are intentionally ignored: the only
 * code paths using them (CustomFieldProvisioner) are best-effort and
 * wrapped in try/catch, so loose matching there is harmless.
 */
final class FakeQueryBuilder
{
    /** @var array<string, mixed> */
    private array $eq = [];

    public function __construct(private string $table)
    {
        if (FakeWhmcs::$throwForTable === $table) {
            throw new \RuntimeException("simulated DB failure for {$table}");
        }
    }

    public function where($a, $b = null, $c = null): self
    {
        if ($a instanceof \Closure) {
            return $this; // grouped constraint — ignore
        }
        if (func_num_args() === 2) {
            $this->eq[$a] = $b;
        } elseif (func_num_args() === 3 && $b === '=') {
            $this->eq[$a] = $c;
        }
        return $this;
    }

    public function orWhere($a, $b = null, $c = null): self
    {
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    private function matching(): array
    {
        return array_values(array_filter(
            FakeWhmcs::rows($this->table),
            function (array $row): bool {
                foreach ($this->eq as $k => $v) {
                    if (!array_key_exists($k, $row) || (string)$row[$k] !== (string)$v) {
                        return false;
                    }
                }
                return true;
            }
        ));
    }

    public function value(string $column): mixed
    {
        $m = $this->matching();
        return $m === [] ? null : ($m[0][$column] ?? null);
    }

    public function exists(): bool
    {
        return $this->matching() !== [];
    }

    /** @return array<int, object> */
    public function get(): array
    {
        return array_map(static fn(array $r): object => (object)$r, $this->matching());
    }

    public function first(): ?object
    {
        $m = $this->matching();
        return $m === [] ? null : (object)$m[0];
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): bool
    {
        FakeWhmcs::$tables[$this->table][] = $row;
        return true;
    }

    /** @param array<string, mixed> $row */
    public function insertGetId(array $row): int
    {
        $maxId = 0;
        foreach (FakeWhmcs::rows($this->table) as $r) {
            $maxId = max($maxId, (int)($r['id'] ?? 0));
        }
        $row['id'] ??= $maxId + 1;
        FakeWhmcs::$tables[$this->table][] = $row;
        return (int)$row['id'];
    }

    /** @return array<int, mixed> */
    public function pluck(string $column): array
    {
        return array_map(static fn(array $r) => $r[$column] ?? null, $this->matching());
    }

    /** @param array<string, mixed> $values @return int affected rows */
    public function update(array $values): int
    {
        $rows = &FakeWhmcs::$tables[$this->table];
        $rows ??= [];
        $n = 0;
        foreach ($rows as &$row) {
            $match = true;
            foreach ($this->eq as $k => $v) {
                if (!array_key_exists($k, $row) || (string)$row[$k] !== (string)$v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $row = array_merge($row, $values);
                $n++;
            }
        }
        unset($row);
        return $n;
    }

    public function delete(): int
    {
        $rows = &FakeWhmcs::$tables[$this->table];
        if (!is_array($rows)) {
            return 0;
        }
        $kept = [];
        $deleted = 0;
        foreach ($rows as $row) {
            $match = true;
            foreach ($this->eq as $k => $v) {
                if (!array_key_exists($k, $row) || (string)$row[$k] !== (string)$v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $deleted++;
            } else {
                $kept[] = $row;
            }
        }
        $rows = $kept;
        return $deleted;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        $rows = &FakeWhmcs::$tables[$this->table];
        $rows ??= [];
        foreach ($rows as &$row) {
            $match = true;
            foreach ($attributes as $k => $v) {
                if (!array_key_exists($k, $row) || (string)$row[$k] !== (string)$v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $row = array_merge($row, $values);
                return true;
            }
        }
        unset($row);
        $rows[] = array_merge($attributes, $values);
        return true;
    }
}
