<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Database\Interfaces\TableExistsStrategy as CoreTableExistsStrategy;
use Phoenix\Integrations\WordPress\Traits\CanModifyWordPressDatabase;

class TableExistsStrategy implements CoreTableExistsStrategy
{
    use CanModifyWordPressDatabase;

    /**
     * Returns true if the specified table exists.
     *
     * @param string $tableName
     * @return bool
     */
    public function exists(string $tableName): bool
    {
        return $this->wpdbGetVar("SHOW TABLES LIKE %s", $tableName) === $tableName;
    }
}