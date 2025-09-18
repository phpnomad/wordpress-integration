<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use InvalidArgumentException;
use PHPNomad\Tasks\Interfaces\Task;
use PHPNomad\Tasks\Interfaces\TaskStrategy;

class TaskScheduler implements TaskStrategy
{
    /**
     * @inheritDoc
     */
    public function dispatch(object $task): void
    {
        if (!$task instanceof Task) {
            throw new InvalidArgumentException('Task must implement Task interface');
        }

        if (!wp_next_scheduled($this->getActionName(get_class($task)), [$task])) {
            wp_schedule_single_event(time(), $this->getActionName(get_class($task)), [$task]);
        }
    }

    /**
     * Gets the name of the WordPress action for the given task class.
     *
     * @param class-string<Task> $taskClass
     * @return string
     */
    protected function getActionName(string $taskClass): string
    {
        return 'phpnomad_task__' . $taskClass::getId();
    }

    /**
     * @inheritDoc
     */
    public function attach(string $taskClass, callable $handler): void
    {
        add_action($this->getActionName($taskClass), $handler);
    }
}