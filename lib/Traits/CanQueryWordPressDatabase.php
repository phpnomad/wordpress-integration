<?php

namespace PHPNomad\Integrations\WordPress\Traits;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Integrations\WordPress\Database\ClauseBuilder;
use PHPNomad\Integrations\WordPress\Database\QueryBuilder as WordPressQueryBuilder;
use PHPNomad\Utils\Helpers\Arr;
use Throwable;

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
            $query = $queryBuilder->build();
            $result = $wpdb->get_results($query, ARRAY_A);
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get results failed. Invalid query: ' . $e->getMessage(), 500, $e);
        }

        if (is_null($result)) {
            throw new DatastoreErrorException('Get results failed - ' . $wpdb->last_error);
        }

        if (empty($result)) {
            throw new RecordNotFoundException('No records found for query: ' . $query);
        }

        return $result;
    }

    /**
     * Gets a batch of rows using a writer-consistent read path after a write.
     *
     * @param QueryBuilder $queryBuilder
     * @return array<string, mixed>[]|array<int>
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function wpdbGetResultsAfterWrite(QueryBuilder $queryBuilder): array
    {
        global $wpdb;

        if (method_exists($wpdb, 'send_reads_to_masters')) {
            $wpdb->send_reads_to_masters();

            return $this->wpdbGetResults($queryBuilder);
        }

        if (false === $wpdb->query('START TRANSACTION')) {
            throw new DatastoreErrorException('Consistent read failed - could not start transaction: ' . $wpdb->last_error);
        }

        try {
            $result = $this->wpdbGetResults($queryBuilder);

            if (false === $wpdb->query('COMMIT')) {
                throw new DatastoreErrorException('Consistent read failed - could not commit transaction: ' . $wpdb->last_error);
            }

            return $result;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');

            throw $e;
        }
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
            $query = $queryBuilder->build();
            $result = $wpdb->get_row($query, ARRAY_A);
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get row failed. Invalid query', 500, $e);
        }

        if (!$result) {
            if (!empty($wpdb->last_error)) {
                throw new DatastoreErrorException('Get row failed - ' . $wpdb->last_error);
            }

            throw new RecordNotFoundException('No record found for query: ' . $query);
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

        if (false === $wpdb->query('START TRANSACTION')) {
            throw new DatastoreErrorException('Insert failed - could not start transaction: ' . $wpdb->last_error);
        }

        try {
            if (empty($data)) {
                $inserted = $wpdb->query('INSERT INTO ' . $table->getName() . '() VALUES ();');
            } else {
                $inserted = $wpdb->insert($table->getName(), $data, $this->getFormats($data));
            }

            if (false === $inserted) {
                throw new DatastoreErrorException('Insert failed - ' . $wpdb->last_error);
            }

            $fields = $table->getFieldsForIdentity();
            $ids = Arr::process($fields)
                ->reduce(function ($acc, $field) use ($data) {
                    if (isset($data[$field])) {
                        $acc[$field] = $data[$field];
                    }

                    return $acc;
                }, [])
                ->toArray();

            if (count($ids) !== count($fields)) {
                $ids = ['id' => $wpdb->insert_id];
            }

            if (false === $wpdb->query('COMMIT')) {
                throw new DatastoreErrorException('Insert failed - could not commit transaction: ' . $wpdb->last_error);
            }

            return $ids;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');

            throw $e;
        }
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

        if (empty($data)) {
            throw new DatastoreErrorException('Update failed - no update data provided.');
        }

        $result = $wpdb->update($table->getName(), $data, $where, $this->getFormats($data), $this->getFormats($where));

        if (false === $result) {
            throw new DatastoreErrorException('Update failed - ' . $wpdb->last_error);
        }

        // When no records were updated, we need to figure out if this is because the record couldn't be found.
        if (0 === $result) {
            $firstItemKey = array_keys($where);
            $firstItem = array_values($where);

            $queryBuilder = new WordPressQueryBuilder();
            $firstKey = array_shift($firstItemKey);
            $clauseBuilder = (new ClauseBuilder())
                ->useTable($table)
                ->where($firstKey, '=', array_shift($firstItem));

            foreach ($where as $key => $value) {
                $clauseBuilder->andWhere($key, '=', $value);
            }

            $queryBuilder
                ->from($table)
                ->count($firstKey)
                ->where($clauseBuilder);

            if (0 === (int)$this->wpdbGetVar($queryBuilder)) {
                throw new RecordNotFoundException(sprintf(
                    'Update failed because no record exists in table "%s" for identity %s. Requested changes: %s.',
                    $table->getName(),
                    $this->encodeExceptionContext($where),
                    $this->encodeExceptionContext($data)
                ));
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
            $query = $queryBuilder->build();
            $result = $wpdb->get_var($query);
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get var failed - Invalid query', 500, $e);
        }

        if (is_null($result)) {
            if (empty($wpdb->last_error)) {
                throw new RecordNotFoundException('No value found for query: ' . $query);
            } else {
                throw new DatastoreErrorException('Get var failed - ' . $wpdb->last_error);
            }
        }

        return $result;
    }

    /**
     * Formats exception context so datastore errors carry the table identity and payloads
     * that triggered the failing operation.
     *
     * @param array<string, mixed> $context
     */
    protected function encodeExceptionContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[unserializable context]' : $encoded;
    }
}
