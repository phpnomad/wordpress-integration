<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Auth\Interfaces\SecretProvider;
use PHPNomad\Integrations\WordPress\Registries\WordPressTaskHandlerRegistry;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Tasks\Exceptions\TaskCreateFailedException;
use PHPNomad\Tasks\Exceptions\TaskDispatchFailedException;
use PHPNomad\Tasks\Exceptions\TaskException;
use PHPNomad\Tasks\Interfaces\IdempotencyStore;
use PHPNomad\Tasks\Interfaces\IsIdempotent;
use PHPNomad\Tasks\Interfaces\Task;
use PHPNomad\Tasks\Interfaces\TaskStrategy;

class WordPressTaskStrategy implements TaskStrategy
{
    protected WordPressTaskHandlerRegistry $registry;
    protected LoggerStrategy $logger;
    protected SecretProvider $secretProvider;
    protected IdempotencyStore $idempotencyStore;

    public function __construct(
        WordPressTaskHandlerRegistry $registry,
        LoggerStrategy $logger,
        SecretProvider $secretProvider,
        IdempotencyStore $idempotencyStore
    ) {
        $this->secretProvider = $secretProvider;
        $this->registry = $registry;
        $this->logger = $logger;
        $this->idempotencyStore = $idempotencyStore;
    }

    /**
     * @inheritDoc
     * @throws TaskException
     */
    public function dispatch(object $task): void
    {
        if (!$task instanceof Task) {
            throw new TaskDispatchFailedException('Task must implement Task interface');
        }

        if (!function_exists('as_enqueue_async_action')) {
            throw new TaskDispatchFailedException('Action Scheduler is not available.');
        }

        $hookName = $this->getHookName($task);
        $payload  = $task->toPayload();

        $sig = $this->generateSignature($task::class, $payload);

        if (!$sig) {
            throw new TaskDispatchFailedException('Task payload is not JSON-encodable.');
        }

        // Enqueue the task for async execution via Action Scheduler
        as_enqueue_async_action($hookName, [$payload, $task::class, $sig], 'phpnomad');
    }

    /**
     * @inheritDoc
     */
    public function attach(string $taskClass, callable $handler): void
    {
        $hookName = $this->getHookName($taskClass);

        // Register the handler with the registry
        $this->registry->attach($taskClass, $handler);

        // Register WordPress action callback if not already registered
        if (!has_action($hookName, [$this, 'handleTask'])) {
            add_action($hookName, [$this, 'handleTask'], 10, 3);
        }
    }

    /**
     * Internal callback that Action Scheduler will invoke.
     * This method unserializes the task and invokes all registered handlers.
     *
     * @param array $payload
     * @param class-string<Task> $task
     * @param string $sig
     * @return void
     */
    public function handleTask(array $payload, string $taskClass, string $sig): void
    {
        if (!is_subclass_of($taskClass, Task::class)) {
            return;
        }

        $expected = $this->generateSignature($taskClass, $payload);
        if ($expected === null || !hash_equals($expected, $sig)) {
            $this->logger->notice('Invalid task signature for ' . $taskClass::getId());
            return;
        }

        try {
            $task = $taskClass::fromPayload($payload);
        } catch (TaskCreateFailedException $e) {
            $this->logger->logException($e);
            return;
        }

        $locked = false;

        try {
            if ($task instanceof IsIdempotent) {
                if ($this->idempotencyStore->isDone($task)) {
                    return;
                }

                if (!$this->idempotencyStore->acquire($task, 600)) {
                    return;
                }

                $locked = true;
            }

            foreach ($this->registry->getHandlers($task) as $handler) {
                try {
                    $handler($task);
                } catch (\Throwable $e) {
                    $this->logger->logException($e);
                    throw $e;
                }
            }

            if ($task instanceof IsIdempotent) {
                $this->idempotencyStore->markDone($task, $task->idempotencyTtlSeconds());
            }
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            if ($locked && $task instanceof IsIdempotent) {
                $this->idempotencyStore->release($task);
            }
        }
    }

    /**
     * @param class-string<Task> $task
     * @param array $payload
     * @return string|null
     */
    private function generateSignature(string $taskClass, array $payload): ?string
    {
        $json = wp_json_encode($payload);
        if ($json === false) {
            return null;
        }

        $message = $taskClass . '|' . $taskClass . '|' . $json;

        return hash_hmac('sha256', $message, $this->secretProvider->getSecret());
    }


    /**
     * Generate the WordPress hook name for a task.
     *
     * @param class-string<Task>|Task $task
     * @return string
     */
    protected function getHookName(Task|string $task): string
    {
        return 'phpnomad_task_' . $task::getId();
    }
}
