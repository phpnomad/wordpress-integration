<?php

namespace Phoenix\Integrations\WordPress\Strategies;

use Phoenix\Database\Exceptions\TableDropFailedException;
use Phoenix\Database\Interfaces\TableDeleteStrategy as CoreTableDeleteStrategy;
use Phoenix\Datastore\Exceptions\DatastoreErrorException;
use Phoenix\Integrations\WordPress\Traits\CanModifyWordPressDatabase;

class TableDeleteStrategy implements CoreTableDeleteStrategy
{
    use CanModifyWordPressDatabase;

    /**
     * Drops the specified table.
     *
     * @param string $tableName
     * @return void
     * @throws TableDropFailedException
     */
    public function delete(string $tableName): void
    {
        try {
            $this->wpdbQuery("DROP TABLE IF EXISTS $tableName");
        } catch (DatastoreErrorException $e) {
            throw new TableDropFailedException($e->getMessage(), $e->getCode(), $e);
        }
    }
}