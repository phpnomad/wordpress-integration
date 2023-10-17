<?php

namespace Phoenix\Integrations\WordPress\Traits;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\DuplicateEntryException;
use Phoenix\Database\Exceptions\QueryBuilderException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Utils\Helpers\Str;

trait CanQueryWordPressDatabase
{
    use CanGetDataFormats;

    /**
     * Gets a batch of rows using wpdb.
     * @return array<string, mixed>[]|array<int>
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    protected function wpdbGetResults(): array
    {
        global $wpdb;
        try {
            $result = $wpdb->get_results($this->queryBuilder->build(), ARRAY_A);
        } catch (QueryBuilderException $e) {
            throw new DatabaseErrorException('Get results failed. Invalid query', 500, $e);
        }

        if (is_null($result)) {
            throw new DatabaseErrorException($wpdb->error);
        }

        if (empty($result)) {
            throw new RecordNotFoundException();
        }

        return $result;
    }

    /**
     * Gets a single row using wpdb.
     * @return array<string, mixed>
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    protected function wpdbGetRow(): array
    {
        global $wpdb;

        try {
            $result = $wpdb->get_row($this->queryBuilder->build(), ARRAY_A);
        } catch (QueryBuilderException $e) {
            throw new DatabaseErrorException('Get row failed. Invalid query', 500, $e);
        }

        if (!$result) {
            if(!empty($wpdb->last_error)){
                throw new DatabaseErrorException('Get row failed - ' . $wpdb->last_error);
            }

            throw new RecordNotFoundException('Could not get the specified row because it does not exist.');
        }

        return $result;
    }

    /**
     * Insert a record into the database.
     * @param string $table
     * @param array<string, float|int|string> $data
     * @return int
     * @throws DatabaseErrorException
     */
    protected function wpdbInsert(string $table, array $data): int
    {
        global $wpdb;

        if (false === $wpdb->insert($table, $data, $this->getFormats($data))) {
            throw new DatabaseErrorException('Insert failed - ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    /**
     * @param Table $table
     * @param array $data
     * @param array $where
     * @return int
     * @throws DatabaseErrorException
     */
    protected function wpdbUpdate(Table $table, array $data, array $where): int
    {
        global $wpdb;

        $result = $wpdb->update($table->getName(), $data, $where, $this->getFormats($data), $this->getFormats($where));

        if (false === $result) {
            throw new DatabaseErrorException('Update failed - ' . $wpdb->last_error);
        }

        // When no records were updated, we need to figure out if this is because the record couldn't be found.
        if (0 === $result) {
            $firstItemKey = array_keys($where);
            $firstItem = array_values($where);

            $this->queryBuilder
                ->useTable($table)
                ->count('id')
                ->from()
                ->where(array_shift($firstItemKey), '=', array_shift($firstItem));

            foreach ($where as $key => $value) {
                $this->queryBuilder->andWhere($key, '=', $value);
            }

            if (0 === (int) $this->wpdbGetVar()) {
                throw new RecordNotFoundException('The update failed because no record exists.');
            }
        }

        return $wpdb->insert_id;
    }

    /**
     * Deletes a record from the database.
     * @param string $table
     * @param array $where
     * @return void
     * @throws DatabaseErrorException
     */
    protected function wpdbDelete(string $table, array $where): void
    {
        global $wpdb;

        if (false === $wpdb->delete($table, $where, $this->getFormats($where))) {
            throw new DatabaseErrorException('Delete failed - ' . $wpdb->last_error);
        }
    }

    /**
     * Gets a single variable from the database.
     *
     * @return string
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    protected function wpdbGetVar(): string
    {
        global $wpdb;
        try {
            $result = $wpdb->get_var($this->queryBuilder->build());
        } catch (QueryBuilderException $e) {
            throw new DatabaseErrorException('Get var failed - Invalid query', 500, $e);
        }

        if (is_null($result)) {
            if (empty($wpdb->last_error)) {
                throw new RecordNotFoundException();
            } else {
                throw new DatabaseErrorException('Get var failed - ' . $wpdb->last_error);
            }
        }

        return $result;
    }
}
