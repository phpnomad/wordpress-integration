<?php

namespace Phoenix\Integrations\WordPress\Traits;

use Phoenix\Database\Exceptions\QueryBuilderException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Datastore\Exceptions\DatastoreErrorException;
use Phoenix\Utils\Helpers\Arr;

trait CanQueryWordPressDatabase
{
    use CanGetDataFormats;

    /**
     * Gets a batch of rows using wpdb.
     * @return array<string, mixed>[]|array<int>
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function wpdbGetResults(QueryBuilder $queryBuilder): array
    {
        global $wpdb;
        try {
            $result = $wpdb->get_results($queryBuilder->build(), ARRAY_A);
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get results failed. Invalid query', 500, $e);
        }

        if (is_null($result)) {
            throw new DatastoreErrorException($wpdb->error);
        }

        if (empty($result)) {
            throw new RecordNotFoundException();
        }

        return $result;
    }

    /**
     * Gets a single row using wpdb.
     * @return array<string, mixed>
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function wpdbGetRow(QueryBuilder $queryBuilder): array
    {
        global $wpdb;

        try {
            $result = $wpdb->get_row($queryBuilder->build(), ARRAY_A);
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get row failed. Invalid query', 500, $e);
        }

        if (!$result) {
            if(!empty($wpdb->last_error)){
                throw new DatastoreErrorException('Get row failed - ' . $wpdb->last_error);
            }

            throw new RecordNotFoundException('Could not get the specified row because it does not exist.');
        }

        return $result;
    }

    /**
     * Insert a record into the database.
     * @param Table $table
     * @param array<string, float|int|string> $data
     * @return array<string, int>
     * @throws DatastoreErrorException
     */
    protected function wpdbInsert(Table $table, array $data): array
    {
        global $wpdb;

        if (false === $wpdb->insert($table->getName(), $data, $this->getFormats($data))) {
            throw new DatastoreErrorException('Insert failed - ' . $wpdb->last_error);
        }

        $fields = $table::getFieldsForIdentity();
        $ids = Arr::process($fields)
            ->flip()
            ->intersect($data, $fields)
            ->toArray();

        if(count($ids) === count($fields)){
            return $ids;
        }

        return ['id' => $wpdb->insert_id];
    }

    /**
     * @param Table $table
     * @param array $data
     * @param array $where
     * @return int
     * @throws DatastoreErrorException
     */
    protected function wpdbUpdate(Table $table, array $data, array $where): void
    {
        global $wpdb;

        $result = $wpdb->update($table->getName(), $data, $where, $this->getFormats($data), $this->getFormats($where));

        if (false === $result) {
            throw new DatastoreErrorException('Update failed - ' . $wpdb->last_error);
        }

        // When no records were updated, we need to figure out if this is because the record couldn't be found.
        if (0 === $result) {
            $firstItemKey = array_keys($where);
            $firstItem = array_values($where);

            $queryBuilder = new \Phoenix\Integrations\WordPress\Database\QueryBuilder();

            $queryBuilder
                ->count('id')
                ->from($table)
                ->where(array_shift($firstItemKey), '=', array_shift($firstItem));

            foreach ($where as $key => $value) {
                $queryBuilder->andWhere($key, '=', $value);
            }

            if (0 === (int) $this->wpdbGetVar($queryBuilder)) {
                throw new RecordNotFoundException('The update failed because no record exists.');
            }
        }
    }

    /**
     * Deletes a record from the database.
     * @param string $table
     * @param array $where
     * @return void
     * @throws DatastoreErrorException
     */
    protected function wpdbDelete(Table $table, array $where): void
    {
        global $wpdb;

        if (false === $wpdb->delete($table->getName(), $where, $this->getFormats($where))) {
            throw new DatastoreErrorException('Delete failed - ' . $wpdb->last_error);
        }
    }

    /**
     * Gets a single variable from the database.
     *
     * @param QueryBuilder $queryBuilder
     * @return string
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function wpdbGetVar(QueryBuilder $queryBuilder): string
    {
        global $wpdb;
        try {
            $result = $wpdb->get_var($queryBuilder->build());
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get var failed - Invalid query', 500, $e);
        }

        if (is_null($result)) {
            if (empty($wpdb->last_error)) {
                throw new RecordNotFoundException();
            } else {
                throw new DatastoreErrorException('Get var failed - ' . $wpdb->last_error);
            }
        }

        return $result;
    }
}
