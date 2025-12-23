<?php

namespace PHPNomad\Integrations\WordPress\Registries;

use PHPNomad\Tasks\Interfaces\Task;

class WordPressTaskHandlerRegistry
{
    protected array $handlers = [];

    /**
     * @param class-string<Task> $taskClass
     * @param callable $handler
     * @return void
     */
    public function attach(string $taskClass, callable $handler): void
    {
        $this->handlers[$taskClass::getId()][] = $handler;
    }

    public function getHandlers(Task $task): array
    {
        return $this->handlers[$task::getId()] ?? [];
    }
}
