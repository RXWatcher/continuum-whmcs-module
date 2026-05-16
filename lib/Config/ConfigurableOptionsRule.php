<?php

declare(strict_types=1);

namespace Continuum\WhmcsModule\Config;

final class ConfigurableOptionsRule
{
    public function __construct(
        private string $optionName,
        private string $match,
        private string $attribute,
        private string $op,
        private mixed $value,
    ) {
    }

    public function optionName(): string
    {
        return $this->optionName;
    }

    public function match(): string
    {
        return $this->match;
    }

    public function attribute(): string
    {
        return $this->attribute;
    }

    public function op(): string
    {
        return $this->op;
    }

    public function value(): mixed
    {
        return $this->value;
    }
}
