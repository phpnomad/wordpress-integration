<?php

declare(strict_types=1);

namespace PHPNomad\Integrations\WordPress\Tests\Integration\Support;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;

final class ContractTable implements Table
{
    /**
     * @param list<Column> $columns
     * @param non-empty-list<string> $identity
     */
    public function __construct(
        private string $name,
        private string $alias,
        private array $columns,
        private array $identity
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getTableVersion(): string
    {
        return '1';
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getIndices(): array
    {
        return [];
    }

    public function getCharset(): ?string
    {
        return 'utf8mb4';
    }

    public function getCollation(): ?string
    {
        return 'utf8mb4_unicode_ci';
    }

    public function getFieldsForIdentity(): array
    {
        return $this->identity;
    }

    public function getUnprefixedName(): string
    {
        return $this->name;
    }

    public function getSingularUnprefixedName(): string
    {
        return $this->alias;
    }
}
