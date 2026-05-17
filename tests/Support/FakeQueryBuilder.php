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
}
